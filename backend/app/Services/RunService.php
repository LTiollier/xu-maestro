<?php

namespace App\Services;

use App\Drivers\DriverInterface;
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
        private readonly DriverInterface $driver,
        private readonly YamlService $yamlService,
        private readonly ArtifactService $artifactService,
        private readonly CheckpointService $checkpointService,
    ) {}

    public function execute(string $runId, string $workflowFile, string $brief): void
    {
        $workflow  = $this->yamlService->load($workflowFile);
        $createdAt = now()->toIso8601String();

        cache()->put("run:{$runId}", ['status' => 'running', 'startedAt' => $createdAt], 3600);

        $agentResults    = [];
        $completedAgents = [];

        try {
            $runPath = $this->artifactService->initializeRun($runId, $workflowFile, $brief);
            cache()->put("run:{$runId}:path", $runPath, 7200); // clé dédiée, non supprimée en finally
            $context = $this->artifactService->getContextContent($runPath);

            $startedAt = microtime(true);

            foreach ($workflow['agents'] as $stepIndex => $agent) {
                $agentId = $agent['id'];

                if (cache()->get("run:{$runId}:cancelled", false)) {
                    throw new RunCancelledException($runId);
                }

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

                event(new AgentStatusChanged($runId, $agentId, 'working', $stepIndex, ''));

                $attempt       = 0;
                $totalAttempts = $maxRetries + 1;
                do {
                    $attempt++;

                    if (cache()->get("run:{$runId}:cancelled", false)) {
                        throw new RunCancelledException($runId);
                    }

                    try {
                        $rawOutput = $this->driver->execute(
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
                        throw $e;
                    }
                } while ($attempt <= $maxRetries);

                $this->artifactService->appendAgentOutput($runPath, $agentId, $rawOutput);

                $completedAgents[] = $agentId;

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

                $agentResults[] = [
                    'id'     => $agentId,
                    'status' => $decoded['status'],
                ];
            }

            $duration = (int) round((microtime(true) - $startedAt) * 1000);

            event(new RunCompleted($runId, $duration, count($agentResults), 'completed', $runPath));
        } finally {
            cache()->forget("run:{$runId}");
            cache()->forget("run:{$runId}:cancelled");
            // Note: error_emitted n'est PAS effacé ici — le finally s'exécute avant
            // que SseController::catch() puisse lire le flag. Le TTL 60s gère le cleanup.
            cache()->put("run:{$runId}:done", true, 3600);
        }
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
