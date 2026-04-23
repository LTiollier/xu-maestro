<?php

declare(strict_types=1);

namespace App\Services;

use App\Drivers\DriverResolver;
use App\Events\AgentStatusChanged;
use App\Events\RunError;
use App\Exceptions\RunCancelledException;

class SubWorkflowExecutor
{
    use \App\Support\SanitizesEnvCredentials;

    public function __construct(
        private readonly DriverResolver $driverResolver,
        private readonly YamlService $yamlService,
        private readonly ArtifactService $artifactService,
        private readonly AgentContextBuilder $contextBuilder,
        private readonly JsonOutputValidator $jsonValidator,
        private readonly GitService $gitService,
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

            $loopConfig = $nodeAgent['loop'] ?? null;
            $items      = $loopConfig ? $this->resolveLoopItems($loopConfig['over'], $subWorkflow['project_path']) : [null];
            $baseContext = $this->artifactService->getContextContent($runPath);

            foreach ($items as $itemIndex => $item) {
                $variables = $item !== null ? [$loopConfig['as'] => $item] : [];
                $iterationContext = $baseContext;
                
                if ($item !== null) {
                    event(new AgentBubble($runId, $nodeId, "Démarrage de l'itération " . ($itemIndex + 1) . "/" . count($items) . " : " . $item, $stepIndex));
                }

                foreach ($subWorkflow['agents'] as $subAgent) {
                    if (cache()->get("run:{$runId}:cancelled", false)) {
                        throw new RunCancelledException($runId);
                    }

                    $prefixedId = $nodeId . '--' . ($item !== null ? ($itemIndex + 1) . '--' : '') . $subAgent['id'];

                    $timeout = isset($subAgent['timeout']) && is_int($subAgent['timeout']) && $subAgent['timeout'] > 0
                        ? $subAgent['timeout']
                        : (int) (config('xu-maestro.default_timeout') ?? 120);

                    $subIsMandatory = isset($subAgent['mandatory']) && $subAgent['mandatory'] === true;

                    $systemPrompt = $this->contextBuilder->resolveSystemPrompt($subAgent, $variables);
                    $driver       = $this->driverResolver->for($subAgent['engine']);

                    $rawOutput = $driver->execute(
                        $subWorkflow['project_path'],
                        $systemPrompt,
                        $this->contextBuilder->build($iterationContext, $subAgent, false, $variables),
                        $timeout
                    );

                    $this->artifactService->appendAgentOutput($runPath, $prefixedId, $rawOutput);
                    
                    // Git Checkpoint (Auto-Commit)
                    $this->gitService->runCheckpoint(
                        $runId,
                        $subWorkflow['project_path'],
                        $subWorkflow,
                        $subAgent,
                        $rawOutput,
                        $stepIndex
                    );
                    
                    // Update iterationContext for next sub-agent in SAME iteration
                    $iterationContext .= "\n---\n## Agent: {$prefixedId}\n" . $this->sanitizeEnvCredentials($rawOutput) . "\n";

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
