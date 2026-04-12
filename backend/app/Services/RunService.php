<?php

namespace App\Services;

use App\Drivers\DriverResolver;
use App\Events\AgentBubble;
use App\Events\AgentStatusChanged;
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
        $currentAgentId = $workflow['agents'][$startStep]['id'] ?? null;
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
        $workflowFile = $workflow['file'];
        $agentResults = array_map(fn ($id) => ['id' => $id, 'status' => 'done'], $completedAgents);
        $context      = $this->artifactService->getContextContent($runPath);

        foreach ($workflow['agents'] as $stepIndex => $agent) {
            if ($stepIndex < $startStep) {
                continue; // Skip agents déjà complétés dans un retry
            }

            $agentId = $agent['id'];

            if (cache()->get("run:{$runId}:cancelled", false)) {
                throw new RunCancelledException($runId);
            }

            $driver = $this->driverResolver->for($agent['engine']);

            // Patch A5 rétro Épic 2 : éviter (int) config(..., 120) qui caste null en 0
            $timeout = isset($agent['timeout']) && is_int($agent['timeout']) && $agent['timeout'] > 0
                ? $agent['timeout']
                : (int) (config('xu-workflow.default_timeout') ?? 120);

            $systemPrompt = $this->resolveSystemPrompt($agent);

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
            // (ses events ont déjà été émis dans executeFromCheckpoint avant la boucle).
            // $firstWorkingEmitted = true quand executeFromCheckpoint a déjà émis l'event du startStep.
            if ($stepIndex > $startStep || ($stepIndex === $startStep && !$firstWorkingEmitted)) {
                event(new AgentStatusChanged($runId, $agentId, 'working', $stepIndex, ''));
            }

            $attempt       = 0;
            $totalAttempts = $maxRetries + 1;
            do {
                $attempt++;

                if (cache()->get("run:{$runId}:cancelled", false)) {
                    throw new RunCancelledException($runId);
                }

                try {
                    $rawOutput = $driver->execute(
                        $workflow['project_path'],
                        $systemPrompt,
                        $context,
                        $timeout
                    );
                    $decoded = $this->validateJsonOutput($agentId, $rawOutput);
                    break; // succès — sortir de la boucle
                } catch (CliExecutionException $e) {
                    if ($attempt <= $maxRetries) {
                        event(new AgentBubble($runId, $agentId, "Tentative {$attempt}/{$totalAttempts} échouée — relance en cours...", $stepIndex));
                        event(new AgentStatusChanged($runId, $agentId, 'working', $stepIndex, ''));
                        continue;
                    }
                    $msg = $e->getMessage();
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
                    throw new CliExecutionException($agentId, $e->exitCode, $e->stderr);
                } catch (ProcessTimedOutException) {
                    if ($attempt <= $maxRetries) {
                        event(new AgentBubble($runId, $agentId, "Tentative {$attempt}/{$totalAttempts} échouée — relance en cours...", $stepIndex));
                        event(new AgentStatusChanged($runId, $agentId, 'working', $stepIndex, ''));
                        continue;
                    }
                    $msg = "Timeout after {$timeout}s";
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
                    throw new AgentTimeoutException($agentId, $timeout);
                } catch (InvalidJsonOutputException $e) {
                    if ($attempt <= $maxRetries) {
                        event(new AgentBubble($runId, $agentId, "Tentative {$attempt}/{$totalAttempts} échouée — relance en cours...", $stepIndex));
                        event(new AgentStatusChanged($runId, $agentId, 'working', $stepIndex, ''));
                        continue;
                    }
                    $msg = "Invalid JSON output from {$agentId}: {$e->getMessage()}";
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

            $this->artifactService->appendAgentOutput($runPath, $agentId, $rawOutput);

            $completedAgents[] = $agentId;
            $agentResults[]    = ['id' => $agentId, 'status' => $decoded['status']];

            // Checkpoint POST-COMPLETION (NFR6) : completedAgents inclut maintenant $agentId
            // Écrit AVANT d'émettre 'done' — garantit qu'aucune progression n'est perdue
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

            $context = $this->artifactService->getContextContent($runPath);

            // Émettre bubble puis done APRÈS écriture checkpoint (NFR6)
            $bubbleMessage = is_string($decoded['output']) ? $decoded['output'] : json_encode($decoded['output']);
            event(new AgentBubble($runId, $agentId, $bubbleMessage, $stepIndex));
            event(new AgentStatusChanged($runId, $agentId, 'done', $stepIndex, ''));
        }

        $duration = (int) round((microtime(true) - $startedAt) * 1000);
        $this->artifactService->finalizeRun($runPath, 'completed', $duration, count($agentResults));
        event(new RunCompleted($runId, $duration, count($agentResults), 'completed', $runPath));
    }

    private function validateJsonOutput(string $agentId, string $rawOutput): array
    {
        $decoded = json_decode($rawOutput, true);

        if ($decoded === null || ! is_array($decoded)) {
            throw new InvalidJsonOutputException($agentId, $rawOutput, 'Not valid JSON object');
        }

        $required = ['step', 'status', 'output', 'next_action', 'errors'];
        foreach ($required as $field) {
            if (! array_key_exists($field, $decoded)) {
                throw new InvalidJsonOutputException($agentId, $rawOutput, "Missing field: {$field}");
            }
        }

        return $decoded;
    }

    private function resolveSystemPrompt(array $agent): string
    {
        if (isset($agent['system_prompt']) && $agent['system_prompt'] !== '') {
            return $agent['system_prompt'];
        }

        if (isset($agent['system_prompt_file']) && $agent['system_prompt_file'] !== '') {
            $path = config('xu-workflow.prompts_path') . '/' . basename($agent['system_prompt_file']);
            if (file_exists($path)) {
                $content = file_get_contents($path);
                if ($content !== false) {
                    return $content;
                }
            }
        }

        return '';
    }
}
