<?php

declare(strict_types=1);

namespace App\Services;

use App\Drivers\DriverResolver;
use App\Events\AgentBubble;
use App\Events\AgentLogLine;
use App\Events\AgentStatusChanged;
use App\Events\AgentWaitingForInput;
use App\Events\RunCompleted;
use App\Events\RunError;
use App\Exceptions\AgentTimeoutException;
use App\Exceptions\CliExecutionException;
use App\Exceptions\InvalidJsonOutputException;
use App\Exceptions\RunCancelledException;
use Illuminate\Process\Exceptions\ProcessTimedOutException;

class RunService
{
    public function __construct(
        private readonly DriverResolver $driverResolver,
        private readonly YamlService $yamlService,
        private readonly ArtifactService $artifactService,
        private readonly CheckpointService $checkpointService,
        private readonly AgentContextBuilder $contextBuilder,
        private readonly JsonOutputValidator $jsonValidator,
        private readonly SubWorkflowExecutor $subWorkflowExecutor,
        private readonly GitService $gitService,
    ) {}

    public function execute(string $runId, string $workflowFile, string $brief): void
    {
        $workflow  = $this->yamlService->load($workflowFile);
        $createdAt = now()->toIso8601String();
        $runPath   = '';
        $startedAt = microtime(true);

        cache()->put("run:{$runId}", ['status' => 'running', 'startedAt' => $createdAt], 3600);

        try {
            $runPath   = $this->artifactService->initializeRun($runId, $workflowFile, $brief);
            cache()->put("run:{$runId}:path", $runPath, 7200);
            $startedAt = microtime(true);

            $this->executeAgents($runId, $workflow, $runPath, $brief, 0, [], $startedAt);
        } catch (RunCancelledException $e) {
            if ($runPath) {
                $this->artifactService->finalizeRun(
                    $runPath, 'cancelled',
                    (int) round((microtime(true) - $startedAt) * 1000),
                    0
                );
            }
            throw $e;
        } finally {
            cache()->forget("run:{$runId}");
            cache()->forget("run:{$runId}:cancelled");
            // Note: error_emitted n'est PAS effacé ici — le finally s'exécute avant
            // que SseController::catch() puisse lire le flag. Le TTL 60s gère le cleanup.
            cache()->put("run:{$runId}:done", true, 3600);
        }
    }

    public function executeFromCheckpoint(string $runId, array $checkpoint): void
    {
        $workflowFile    = $checkpoint['workflowFile'];
        $brief           = $checkpoint['brief'] ?? '';
        $startStep       = (int) ($checkpoint['currentStep'] ?? 0);
        $completedAgents = $checkpoint['completedAgents'] ?? [];

        $workflow = $this->yamlService->load($workflowFile);
        $runPath  = cache()->get("run:{$runId}:path");

        if (! $runPath) {
            throw new \RuntimeException("Run path not found for run: {$runId}");
        }

        // Émettre les events de reprise sur l'agent fautif AVANT d'entrer dans la boucle
        $startStepEntry = $workflow['agents'][$startStep] ?? null;
        $currentAgentId = ($startStepEntry && ! isset($startStepEntry['parallel']))
            ? ($startStepEntry['id'] ?? null)
            : null;
        if ($currentAgentId) {
            event(new AgentStatusChanged($runId, $currentAgentId, 'working', $startStep, ''));
            event(new AgentBubble($runId, $currentAgentId, 'Reprise depuis le checkpoint...', $startStep));
        }

        cache()->put("run:{$runId}", ['status' => 'running', 'startedAt' => now()->toIso8601String()], 3600);

        try {
            $startedAt = microtime(true);
            $this->executeAgents($runId, $workflow, $runPath, $brief, $startStep, $completedAgents, $startedAt, true);
        } catch (RunCancelledException $e) {
            $this->artifactService->finalizeRun(
                $runPath, 'cancelled',
                (int) round((microtime(true) - $startedAt) * 1000),
                count($completedAgents)
            );
            throw $e;
        } finally {
            cache()->forget("run:{$runId}");
            cache()->forget("run:{$runId}:cancelled");
            // Note: error_emitted n'est PAS effacé ici — invariant de 3.1/3.2
            cache()->put("run:{$runId}:done", true, 3600);
        }
    }

    private function executeAgents(
        string $runId,
        array  $workflow,
        string $runPath,
        string $brief,
        int    $startStep,
        array  $completedAgents,
        float  $startedAt,
        bool   $firstWorkingEmitted = false,
    ): void {
        $workflowFile  = $workflow['file'];
        $agentResults  = array_map(fn ($id) => ['id' => $id, 'status' => 'done'], $completedAgents);
        $skipNextAgent = false;

        foreach ($workflow['agents'] as $stepIndex => $agent) {
            if ($stepIndex < $startStep) {
                continue; // Skip agents déjà complétés dans un retry
            }

            // Groupe parallèle — exécuter tous les agents simultanément puis continuer
            if (isset($agent['parallel'])) {
                $this->executeParallelGroup(
                    $runId, $agent['parallel'], $workflow, $runPath, $brief,
                    $stepIndex, $completedAgents, $agentResults, $startedAt,
                );
                $skipNextAgent = false;
                continue;
            }

            $agentId     = $agent['id'];
            $isSkippable = isset($agent['skippable']) && $agent['skippable'] === true;

            // Si l'agent précédent a demandé un skip et que cet agent est skippable — on saute
            if ($skipNextAgent && $isSkippable) {
                $skipNextAgent   = false;
                $completedAgents[] = $agentId;
                $agentResults[]  = ['id' => $agentId, 'status' => 'skipped'];
                event(new AgentStatusChanged($runId, $agentId, 'skipped', $stepIndex, ''));
                continue;
            }
            $skipNextAgent = false; // Signal ignoré si l'agent n'est pas skippable

            if (cache()->get("run:{$runId}:cancelled", false)) {
                throw new RunCancelledException($runId);
            }

            // Handle sub-workflow nodes inline
            if ($agent['engine'] === 'sub-workflow') {
                $this->subWorkflowExecutor->execute(
                    $runId, $agent, $runPath, $stepIndex,
                    $completedAgents, $agentResults, $startedAt
                );
                $completedAgents[] = $agentId;
                $agentResults[]    = ['id' => $agentId, 'status' => 'done'];
                continue;
            }

            $driver = $this->driverResolver->for($agent['engine']);

            // Patch A5 rétro Épic 2 : éviter (int) config(..., 120) qui caste null en 0
            $timeout = isset($agent['timeout']) && is_int($agent['timeout']) && $agent['timeout'] > 0
                ? $agent['timeout']
                : (int) (config('xu-maestro.default_timeout') ?? 120);

            // Retry : même pattern que $timeout (champ optionnel, valeurs par défaut sûres)
            $isMandatory = isset($agent['mandatory']) && $agent['mandatory'] === true;
            $maxRetries  = ($isMandatory && isset($agent['max_retries']) && is_int($agent['max_retries']) && $agent['max_retries'] > 0)
                ? $agent['max_retries']
                : 0;

            // Checkpoint PRÉ-AGENT : completedAgents ne contient pas encore $agentId
            $this->checkpointService->write($runPath, [
                'runId'           => $runId,
                'workflowFile'    => $workflowFile,
                'brief'           => $brief,
                'completedAgents' => $completedAgents,
                'currentAgent'    => $agentId,
                'currentStep'     => $stepIndex,
                'context'         => $runPath . '/session.md',
            ]);

            // Émettre AgentStatusChanged(working) sauf pour l'agent fautif en retry
            if ($stepIndex > $startStep || ($stepIndex === $startStep && !$firstWorkingEmitted)) {
                event(new AgentStatusChanged($runId, $agentId, 'working', $stepIndex, ''));
            }

            // --- Logique de Boucle (Loop) ---
            $loopConfig = $agent['loop'] ?? null;
            $items      = $loopConfig ? $this->resolveLoopItems($loopConfig['over'], $workflow['project_path']) : [null];
            
            // On fige le contexte de base pour toutes les itérations (isolation/clear context)
            $baseContext = $this->artifactService->getContextContent($runPath);
            $lastDecoded = null;

            foreach ($items as $index => $item) {
                $variables         = $item !== null ? [$loopConfig['as'] => $item] : [];
                $iterationAgentId  = $item !== null ? $agentId . '--' . ($index + 1) : $agentId;
                $systemPrompt      = $this->contextBuilder->resolveSystemPrompt($agent, $variables);
                $nextAgent         = $workflow['agents'][$stepIndex + 1] ?? null;
                $nextIsSkippable   = isset($nextAgent['skippable']) && $nextAgent['skippable'] === true;

                if ($item !== null) {
                    event(new AgentBubble($runId, $agentId, "Démarrage de l'itération " . ($index + 1) . "/" . count($items) . " : " . $item, $stepIndex));
                }

                // Contexte additionnel accumulé entre chaque échange Q&R du même agent
                $additionalContext = '';

                // Boucle externe : relance le même agent tant qu'il pose des questions
                while (true) {
                    $attempt       = 0;
                    $totalAttempts = $maxRetries + 1;
                    do {
                        $attempt++;

                        if (cache()->get("run:{$runId}:cancelled", false)) {
                            throw new RunCancelledException($runId);
                        }

                        try {
                            $logCallback = function (string $line) use ($runId, $agentId, $stepIndex): void {
                                event(new AgentLogLine($runId, $agentId, $line, $stepIndex));
                            };

                            $rawOutput = $driver->execute(
                                $workflow['project_path'],
                                $systemPrompt,
                                $this->contextBuilder->build($baseContext . $additionalContext, $agent, $nextIsSkippable, $variables),
                                $timeout,
                                $logCallback
                            );
                            $decoded = $this->jsonValidator->validate($iterationAgentId, $rawOutput);
                            break; // succès — sortir de la boucle retry
                        } catch (CliExecutionException $e) {
                            if ($attempt <= $maxRetries) {
                                event(new AgentBubble($runId, $agentId, "Tentative {$attempt}/{$totalAttempts} échouée — relance en cours...", $stepIndex));
                                event(new AgentStatusChanged($runId, $agentId, 'working', $stepIndex, ''));
                                continue;
                            }
                            $msg = $e->getMessage();
                            logger()->error('Agent CLI execution failed', ['runId' => $runId, 'agentId' => $iterationAgentId, 'step' => $stepIndex, 'error' => $msg]);
                            event(new AgentStatusChanged($runId, $agentId, 'error', $stepIndex, $msg));
                            event(new RunError(
                                runId:          $runId,
                                agentId:        $agentId,
                                step:           $stepIndex,
                                message:        $msg,
                                checkpointPath: $runPath . '/checkpoint.json',
                            ));
                            cache()->put("run:{$runId}:error_emitted", true, 60);
                            $this->artifactService->finalizeRun($runPath, 'error', (int) round((microtime(true) - $startedAt) * 1000), count($completedAgents));
                            throw new CliExecutionException($iterationAgentId, $e->exitCode, $e->stderr);
                        } catch (ProcessTimedOutException) {
                            if ($attempt <= $maxRetries) {
                                event(new AgentBubble($runId, $agentId, "Tentative {$attempt}/{$totalAttempts} échouée — relance en cours...", $stepIndex));
                                event(new AgentStatusChanged($runId, $agentId, 'working', $stepIndex, ''));
                                continue;
                            }
                            $msg = "Timeout after {$timeout}s";
                            logger()->error('Agent timed out', ['runId' => $runId, 'agentId' => $iterationAgentId, 'step' => $stepIndex, 'timeout' => $timeout]);
                            event(new AgentStatusChanged($runId, $agentId, 'error', $stepIndex, $msg));
                            event(new RunError(
                                runId:          $runId,
                                agentId:        $agentId,
                                step:           $stepIndex,
                                message:        $msg,
                                checkpointPath: $runPath . '/checkpoint.json',
                            ));
                            cache()->put("run:{$runId}:error_emitted", true, 60);
                            $this->artifactService->finalizeRun($runPath, 'error', (int) round((microtime(true) - $startedAt) * 1000), count($completedAgents));
                            throw new AgentTimeoutException($iterationAgentId, $timeout);
                        } catch (InvalidJsonOutputException $e) {
                            if ($attempt <= $maxRetries) {
                                event(new AgentBubble($runId, $agentId, "Tentative {$attempt}/{$totalAttempts} échouée — relance en cours...", $stepIndex));
                                event(new AgentStatusChanged($runId, $agentId, 'working', $stepIndex, ''));
                                continue;
                            }
                            $msg = "Invalid JSON output from {$iterationAgentId}: {$e->getMessage()}";
                            logger()->error('Agent invalid JSON output', ['runId' => $runId, 'agentId' => $iterationAgentId, 'step' => $stepIndex, 'error' => $e->getMessage()]);
                            event(new AgentStatusChanged($runId, $agentId, 'error', $stepIndex, $msg));
                            event(new RunError(
                                runId:          $runId,
                                agentId:        $agentId,
                                step:           $stepIndex,
                                message:        $msg,
                                checkpointPath: $runPath . '/checkpoint.json',
                            ));
                            cache()->put("run:{$runId}:error_emitted", true, 60);
                            $this->artifactService->finalizeRun($runPath, 'error', (int) round((microtime(true) - $startedAt) * 1000), count($completedAgents));
                            throw $e;
                        }
                    } while ($attempt <= $maxRetries);

                    // Si l'agent demande une interaction utilisateur — pause et polling
                    if ($decoded['status'] === 'waiting_for_input') {
                        $question = (string) $decoded['question'];

                        cache()->put("run:{$runId}:pending_question:{$agentId}", $question, 3600);
                        event(new AgentStatusChanged($runId, $agentId, 'waiting_for_input', $stepIndex, $question));
                        event(new AgentWaitingForInput($runId, $agentId, $question, $stepIndex));

                        // Checkpoint : currentStep reste $stepIndex pour reprendre sur le même agent
                        $this->checkpointService->write($runPath, [
                            'runId'           => $runId,
                            'workflowFile'    => $workflowFile,
                            'brief'           => $brief,
                            'completedAgents' => $completedAgents,
                            'currentAgent'    => $agentId,
                            'currentStep'     => $stepIndex,
                            'context'         => $runPath . '/session.md',
                        ]);

                        // Polling jusqu'à réception de la réponse (max 15 min = 900 itérations)
                        $answer = null;
                        for ($i = 0; $i < 900; $i++) {
                            if (cache()->get("run:{$runId}:cancelled", false)) {
                                throw new RunCancelledException($runId);
                            }
                            $answer = cache()->get("run:{$runId}:user_answer:{$agentId}");
                            if ($answer !== null) {
                                break;
                            }
                            sleep(1);
                        }

                        if ($answer === null) {
                            $msg = "Délai de réponse dépassé pour l'agent {$agentId}";
                            logger()->error('User input timeout', ['runId' => $runId, 'agentId' => $agentId, 'step' => $stepIndex]);
                            event(new AgentStatusChanged($runId, $agentId, 'error', $stepIndex, $msg));
                            event(new RunError(
                                runId:          $runId,
                                agentId:        $agentId,
                                step:           $stepIndex,
                                message:        $msg,
                                checkpointPath: $runPath . '/checkpoint.json',
                            ));
                            cache()->put("run:{$runId}:error_emitted", true, 60);
                            $this->artifactService->finalizeRun($runPath, 'error', (int) round((microtime(true) - $startedAt) * 1000), count($completedAgents));
                            throw new \RuntimeException($msg);
                        }

                        // Consommer la réponse et effacer la clé pour permettre une prochaine question
                        cache()->forget("run:{$runId}:user_answer:{$agentId}");
                        cache()->forget("run:{$runId}:pending_question:{$agentId}");

                        // Accumuler la Q&R dans le contexte de l'agent pour la prochaine itération
                        $qaEntry = "**Question :** {$question}\n**Réponse utilisateur :** {$answer}";
                        $additionalContext .= "\n\n---\n## Échange précédent\n{$qaEntry}";
                        $this->artifactService->appendAgentOutput($runPath, $iterationAgentId, $qaEntry);

                        event(new AgentBubble($runId, $agentId, $answer, $stepIndex));
                        event(new AgentStatusChanged($runId, $agentId, 'working', $stepIndex, ''));

                        // Relancer le même agent avec la réponse injectée dans le contexte
                        continue;
                    }

                    // L'agent a terminé (status != waiting_for_input) — sortir de la boucle
                    break;
                }

                $this->artifactService->appendAgentOutput($runPath, $iterationAgentId, $rawOutput);
                $lastDecoded = $decoded;

                // Émettre bubble pour cette itération
                $bubbleMessage = is_string($decoded['output']) ? $decoded['output'] : json_encode($decoded['output']);
                event(new AgentBubble($runId, $agentId, $bubbleMessage, $stepIndex));
            }

            // Signal de skip : l'agent demande à sauter le prochain (pris en compte si skippable)
            if ($lastDecoded && $lastDecoded['next_action'] === 'skip_next') {
                $skipNextAgent = true;
            }

            $completedAgents[] = $agentId;
            $agentResults[]    = ['id' => $agentId, 'status' => $lastDecoded['status'] ?? 'done'];

            // Checkpoint POST-COMPLETION (NFR6) : completedAgents inclut maintenant $agentId
            $nextStepIndex = $stepIndex + 1;
            $nextAgentId   = $workflow['agents'][$nextStepIndex]['id'] ?? null;
            $this->checkpointService->write($runPath, [
                'runId'           => $runId,
                'workflowFile'    => $workflowFile,
                'brief'           => $brief,
                'completedAgents' => $completedAgents,
                'currentAgent'    => $nextAgentId,
                'currentStep'     => $nextStepIndex,
                'context'         => $runPath . '/session.md',
            ]);

            // Émettre done APRÈS écriture checkpoint
            event(new AgentStatusChanged($runId, $agentId, 'done', $stepIndex, ''));

            // Git Checkpoint (Auto-Commit)
            $this->gitService->runCheckpoint(
                $runId,
                $workflow['project_path'],
                $workflow,
                $agent,
                $rawOutput ?? '', // Use last raw output for commit message generation
                $stepIndex
            );
        }

        $duration = (int) round((microtime(true) - $startedAt) * 1000);
        $this->artifactService->finalizeRun($runPath, 'completed', $duration, count($agentResults));
        event(new RunCompleted($runId, $duration, count($agentResults), 'completed', $runPath));
    }

    private function executeParallelGroup(
        string $runId,
        array  $agents,
        array  $workflow,
        string $runPath,
        string $brief,
        int    $stepIndex,
        array  &$completedAgents,
        array  &$agentResults,
        float  $startedAt,
    ): void {
        $workflowFile = $workflow['file'];
        $agentIds     = array_column($agents, 'id');

        // Checkpoint PRÉ-GROUPE (groupe = étape atomique)
        $this->checkpointService->write($runPath, [
            'runId'           => $runId,
            'workflowFile'    => $workflowFile,
            'brief'           => $brief,
            'completedAgents' => $completedAgents,
            'currentAgent'    => null,
            'currentStep'     => $stepIndex,
            'context'         => $runPath . '/session.md',
        ]);

        // Snapshot du contexte partagé au démarrage du groupe (même base pour tous)
        $baseContext = $this->artifactService->getContextContent($runPath);

        // Émettre working pour tous les agents du groupe simultanément
        foreach ($agents as $agent) {
            event(new AgentStatusChanged($runId, $agent['id'], 'working', $stepIndex, ''));
        }

        // Phase 1 : démarrer tous les processus en async avant d'en attendre un seul
        $pending = [];
        foreach ($agents as $agent) {
            $agentId = $agent['id'];
            $driver  = $this->driverResolver->for($agent['engine']);

            $timeout = isset($agent['timeout']) && is_int($agent['timeout']) && $agent['timeout'] > 0
                ? $agent['timeout']
                : (int) (config('xu-maestro.default_timeout') ?? 120);

            $systemPrompt = $this->contextBuilder->resolveSystemPrompt($agent, []);
            $context      = $this->contextBuilder->build($baseContext, $agent, false, []);

            $logCallback = function (string $line) use ($runId, $agentId, $stepIndex): void {
                event(new AgentLogLine($runId, $agentId, $line, $stepIndex));
            };

            [$process, $getResult] = $driver->startAsync(
                $workflow['project_path'],
                $systemPrompt,
                $context,
                $timeout,
                $logCallback,
            );

            $pending[] = ['agent' => $agent, 'process' => $process, 'getResult' => $getResult];
        }

        // Phase 1.5 : Polling loop to interleave SSE logs from all parallel processes
        while (true) {
            $anyRunning = false;
            foreach ($pending as $entry) {
                try {
                    if ($entry['process']->running()) {
                        $anyRunning = true;
                    }
                } catch (ProcessTimedOutException) {
                    // Handled in Phase 2 per process
                }
            }

            if (!$anyRunning || cache()->get("run:{$runId}:cancelled", false)) {
                break;
            }

            usleep(50000); // 50ms sleep to avoid pegged CPU
        }

        // Phase 2 : collecter les résultats dans l'ordre YAML
        $groupError = null;
        foreach ($pending as $entry) {
            $agent       = $entry['agent'];
            $agentId     = $agent['id'];
            $isMandatory = isset($agent['mandatory']) && $agent['mandatory'] === true;

            if (cache()->get("run:{$runId}:cancelled", false)) {
                foreach ($pending as $p) {
                    if ($p['process']->running()) {
                        $p['process']->signal(SIGTERM);
                    }
                }
                throw new RunCancelledException($runId);
            }

            try {
                $rawOutput = ($entry['getResult'])();
                $decoded   = $this->jsonValidator->validate($agentId, $rawOutput);

                $this->artifactService->writeAgentTempOutput($runPath, $agentId, $rawOutput);

                $bubbleMessage = is_string($decoded['output']) ? $decoded['output'] : json_encode($decoded['output']);
                event(new AgentBubble($runId, $agentId, $bubbleMessage, $stepIndex));
                event(new AgentStatusChanged($runId, $agentId, 'done', $stepIndex, ''));

                // Git Checkpoint (Auto-Commit)
                $this->gitService->runCheckpoint(
                    $runId,
                    $workflow['project_path'],
                    $workflow,
                    $agent,
                    $rawOutput,
                    $stepIndex
                );

                $agentResults[]    = ['id' => $agentId, 'status' => 'done'];
                $completedAgents[] = $agentId;
            } catch (RunCancelledException $e) {
                throw $e;
            } catch (\Throwable $e) {
                $msg = $e->getMessage();
                logger()->error('Parallel agent failed', [
                    'runId'   => $runId,
                    'agentId' => $agentId,
                    'step'    => $stepIndex,
                    'error'   => $msg,
                ]);
                event(new AgentStatusChanged($runId, $agentId, 'error', $stepIndex, $msg));

                if ($isMandatory) {
                    $groupError = $e;
                } else {
                    event(new AgentBubble($runId, $agentId, "[Warning] Agent {$agentId} non-mandatory échoué : {$msg}", $stepIndex));
                }

                $agentResults[] = ['id' => $agentId, 'status' => 'error'];
            }
        }

        // Si un agent mandatory a échoué, propager après avoir traité tout le groupe
        if ($groupError !== null) {
            event(new RunError(
                runId:          $runId,
                agentId:        'parallel-group',
                step:           $stepIndex,
                message:        $groupError->getMessage(),
                checkpointPath: $runPath . '/checkpoint.json',
            ));
            cache()->put("run:{$runId}:error_emitted", true, 60);
            $this->artifactService->finalizeRun(
                $runPath, 'error',
                (int) round((microtime(true) - $startedAt) * 1000),
                count($completedAgents),
            );
            throw $groupError;
        }

        // Fusionner les outputs dans session.md dans l'ordre YAML
        $this->artifactService->mergeParallelOutputs($runPath, $agentIds, $runPath . '/session.md');

        // Checkpoint POST-GROUPE
        $nextStepIndex = $stepIndex + 1;
        $nextAgentEntry = $workflow['agents'][$nextStepIndex] ?? null;
        $nextAgentId    = ($nextAgentEntry && ! isset($nextAgentEntry['parallel']))
            ? ($nextAgentEntry['id'] ?? null)
            : null;
        $this->checkpointService->write($runPath, [
            'runId'           => $runId,
            'workflowFile'    => $workflowFile,
            'brief'           => $brief,
            'completedAgents' => $completedAgents,
            'currentAgent'    => $nextAgentId,
            'currentStep'     => $nextStepIndex,
            'context'         => $runPath . '/session.md',
        ]);
    }

    private function resolveLoopItems(string $over, string $projectPath): array
    {
        $fullPath = $projectPath . '/' . $over;
        $files    = glob($fullPath);
        if ($files === false) {
            return [];
        }

        return array_map(function ($file) use ($projectPath) {
            $relative = str_replace($projectPath, '', $file);

            return ltrim($relative, '/');
        }, $files);
    }
}
