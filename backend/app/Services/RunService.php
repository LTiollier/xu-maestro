<?php

namespace App\Services;

use App\Drivers\DriverInterface;
use App\Exceptions\AgentTimeoutException;
use App\Exceptions\CliExecutionException;
use App\Exceptions\InvalidJsonOutputException;
use App\Exceptions\RunCancelledException;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Str;

class RunService
{
    public function __construct(
        private readonly DriverInterface $driver,
        private readonly YamlService $yamlService,
        private readonly ArtifactService $artifactService,
    ) {}

    public function execute(string $workflowFile, string $brief): array
    {
        $workflow = $this->yamlService->load($workflowFile);

        $runId     = Str::uuid()->toString();
        $createdAt = now()->toIso8601String();

        // Register active run in cache (TTL 1h) — allows DELETE /api/runs/{id} cancellation
        cache()->put("run:{$runId}", ['status' => 'running', 'startedAt' => $createdAt], 3600);

        $agentResults    = [];
        $completedAgents = [];

        try {
            // Initialiser les artefacts du run (dossier + session.md + checkpoint.json + agents/)
            $runPath = $this->artifactService->initializeRun($runId, $workflowFile, $brief);

            // Contexte initial : contenu de session.md (header avec le brief)
            $context = $this->artifactService->getContextContent($runPath);

            $startedAt = microtime(true);

            foreach ($workflow['agents'] as $agent) {
                $agentId = $agent['id'];

                // Check cancellation flag BEFORE spawning next agent
                if (cache()->get("run:{$runId}:cancelled", false)) {
                    throw new RunCancelledException($runId);
                }

                // Per-agent timeout from YAML; fall back to global default
                $timeout = isset($agent['timeout']) && is_int($agent['timeout']) && $agent['timeout'] > 0
                    ? $agent['timeout']
                    : (int) config('xu-workflow.default_timeout', 120);

                $systemPrompt = $this->resolveSystemPrompt($agent);

                // Checkpoint avant spawn : currentAgent mis à jour
                $this->artifactService->writeCheckpoint($runPath, [
                    'runId'           => $runId,
                    'workflowFile'    => $workflowFile,
                    'brief'           => $brief,
                    'completedAgents' => $completedAgents,
                    'currentAgent'    => $agentId,
                    'currentStep'     => 0,
                    'context'         => $runPath . '/session.md',
                ]);

                try {
                    $rawOutput = $this->driver->execute(
                        $workflow['project_path'],
                        $systemPrompt,
                        $context,
                        $timeout
                    );
                } catch (CliExecutionException $e) {
                    throw new CliExecutionException($agentId, $e->exitCode, $e->stderr);
                } catch (ProcessTimedOutException) {
                    throw new AgentTimeoutException($agentId, $timeout);
                }

                // Valider le JSON avant d'écrire les artefacts
                $decoded = $this->validateJsonOutput($agentId, $rawOutput);

                // Artefacts : append session.md (append-only) + save agents/{agentId}.md
                $this->artifactService->appendAgentOutput($runPath, $agentId, $rawOutput);

                $completedAgents[] = $agentId;

                // Contexte mis à jour pour le prochain agent (session.md enrichi)
                $context = $this->artifactService->getContextContent($runPath);

                $agentResults[] = [
                    'id'     => $agentId,
                    'status' => $decoded['status'],
                ];
            }

            $duration = (int) round((microtime(true) - $startedAt) * 1000);

            return [
                'runId'     => $runId,
                'status'    => 'completed',
                'agents'    => $agentResults,
                'duration'  => $duration,
                'createdAt' => $createdAt,
                'runFolder' => $runPath,
            ];
        } finally {
            // NFR4: clean up cache in all cases (success, timeout, cancellation, error)
            cache()->forget("run:{$runId}");
            cache()->forget("run:{$runId}:cancelled");
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
