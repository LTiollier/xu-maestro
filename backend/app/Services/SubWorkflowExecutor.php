<?php

declare(strict_types=1);

namespace App\Services;

use App\Drivers\DriverResolver;
use App\Events\AgentStatusChanged;
use App\Events\RunError;
use App\Exceptions\RunCancelledException;

final class SubWorkflowExecutor
{
    public function __construct(
        private readonly DriverResolver $driverResolver,
        private readonly YamlService $yamlService,
        private readonly ArtifactService $artifactService,
        private readonly AgentContextBuilder $contextBuilder,
        private readonly JsonOutputValidator $jsonValidator,
    ) {}

    public function execute(
        string $runId,
        array  $nodeAgent,
        string $runPath,
        int    $stepIndex,
        array  &$completedAgents,
        array  &$agentResults,
        float  $startedAt,
    ): void {
        $nodeId = $nodeAgent['id'];

        event(new AgentStatusChanged($runId, $nodeId, 'working', $stepIndex, ''));

        try {
            $subWorkflow = $this->yamlService->load($nodeAgent['workflow_file']);

            // Guard against recursive nesting — halt gracefully
            foreach ($subWorkflow['agents'] as $subAgent) {
                if (isset($subAgent['engine']) && $subAgent['engine'] === 'sub-workflow') {
                    throw new \RuntimeException("Recursive sub-workflow nesting is not supported (node: {$nodeId})");
                }
            }

            foreach ($subWorkflow['agents'] as $subAgent) {
                if (cache()->get("run:{$runId}:cancelled", false)) {
                    throw new RunCancelledException($runId);
                }

                $prefixedId = $nodeId . '--' . $subAgent['id'];

                $timeout = isset($subAgent['timeout']) && is_int($subAgent['timeout']) && $subAgent['timeout'] > 0
                    ? $subAgent['timeout']
                    : (int) (config('xu-workflow.default_timeout') ?? 120);

                $subIsMandatory = isset($subAgent['mandatory']) && $subAgent['mandatory'] === true;

                $systemPrompt = $this->contextBuilder->resolveSystemPrompt($subAgent);
                $context      = $this->artifactService->getContextContent($runPath);

                $driver    = $this->driverResolver->for($subAgent['engine']);
                $rawOutput = $driver->execute(
                    $subWorkflow['project_path'],
                    $systemPrompt,
                    $this->contextBuilder->build($context, $subAgent),
                    $timeout
                );

                $this->artifactService->appendAgentOutput($runPath, $prefixedId, $rawOutput);

                try {
                    $this->jsonValidator->validate($prefixedId, $rawOutput);
                } catch (\Throwable $e) {
                    if ($subIsMandatory) {
                        throw $e;
                    }
                    logger()->warning('Non-mandatory sub-agent failed', ['runId' => $runId, 'agentId' => $prefixedId, 'error' => $e->getMessage()]);
                    continue;
                }
            }

            event(new AgentStatusChanged($runId, $nodeId, 'done', $stepIndex, ''));
        } catch (RunCancelledException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $msg = $e->getMessage();
            logger()->error('Sub-workflow node failed', ['runId' => $runId, 'nodeId' => $nodeId, 'step' => $stepIndex, 'error' => $msg]);
            event(new AgentStatusChanged($runId, $nodeId, 'error', $stepIndex, $msg));
            event(new RunError(
                runId:          $runId,
                agentId:        $nodeId,
                step:           $stepIndex,
                message:        $msg,
                checkpointPath: $runPath . '/checkpoint.json',
            ));
            cache()->put("run:{$runId}:error_emitted", true, 60);
            $this->artifactService->finalizeRun($runPath, 'error', (int) round((microtime(true) - $startedAt) * 1000), count($completedAgents));
            throw $e;
        }
    }
}
