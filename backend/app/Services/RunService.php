<?php

namespace App\Services;

use App\Drivers\DriverInterface;
use App\Events\AgentBubble;
use App\Events\AgentStatusChanged;
use App\Events\RunCompleted;
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

            foreach ($workflow['agents'] as $agent) {
                $agentId = $agent['id'];

                if (cache()->get("run:{$runId}:cancelled", false)) {
                    throw new RunCancelledException($runId);
                }

                $timeout = isset($agent['timeout']) && is_int($agent['timeout']) && $agent['timeout'] > 0
                    ? $agent['timeout']
                    : (int) config('xu-workflow.default_timeout', 120);

                $systemPrompt = $this->resolveSystemPrompt($agent);

                $this->artifactService->writeCheckpoint($runPath, [
                    'runId'           => $runId,
                    'workflowFile'    => $workflowFile,
                    'brief'           => $brief,
                    'completedAgents' => $completedAgents,
                    'currentAgent'    => $agentId,
                    'currentStep'     => 0,
                    'context'         => $runPath . '/session.md',
                ]);

                // Émettre 'working' avant le spawn CLI
                event(new AgentStatusChanged($runId, $agentId, 'working', 0, ''));

                try {
                    $rawOutput = $this->driver->execute(
                        $workflow['project_path'],
                        $systemPrompt,
                        $context,
                        $timeout
                    );
                } catch (CliExecutionException $e) {
                    event(new AgentStatusChanged($runId, $agentId, 'error', 0, $e->getMessage()));
                    throw new CliExecutionException($agentId, $e->exitCode, $e->stderr);
                } catch (ProcessTimedOutException) {
                    event(new AgentStatusChanged($runId, $agentId, 'error', 0, "Timeout after {$timeout}s"));
                    throw new AgentTimeoutException($agentId, $timeout);
                }

                $decoded = $this->validateJsonOutput($agentId, $rawOutput);

                $this->artifactService->appendAgentOutput($runPath, $agentId, $rawOutput);

                $completedAgents[] = $agentId;
                $context           = $this->artifactService->getContextContent($runPath);

                // Émettre bubble puis done après succès
                $bubbleMessage = is_string($decoded['output']) ? $decoded['output'] : json_encode($decoded['output']);
                event(new AgentBubble($runId, $agentId, $bubbleMessage, 0));
                event(new AgentStatusChanged($runId, $agentId, 'done', 0, ''));

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
