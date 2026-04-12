<?php

namespace Tests\Unit;

use App\Drivers\DriverInterface;
use App\Events\AgentBubble;
use App\Events\AgentStatusChanged;
use App\Events\RunCompleted;
use App\Events\RunError;
use App\Exceptions\CliExecutionException;
use App\Exceptions\RunCancelledException;
use App\Services\ArtifactService;
use App\Services\CheckpointService;
use App\Services\RunService;
use App\Services\YamlService;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RunServiceRetryFromCheckpointTest extends TestCase
{
    private DriverInterface $mockDriver;
    private YamlService $mockYaml;
    private ArtifactService $mockArtifact;
    private CheckpointService $mockCheckpoint;
    private RunService $service;

    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'array']);
        Event::fake();

        $this->mockDriver     = $this->createMock(DriverInterface::class);
        $this->mockYaml       = $this->createMock(YamlService::class);
        $this->mockArtifact   = $this->createMock(ArtifactService::class);
        $this->mockCheckpoint = $this->createMock(CheckpointService::class);

        $this->mockArtifact->method('getContextContent')->willReturn('# context');

        $this->service = new RunService(
            $this->mockDriver, $this->mockYaml, $this->mockArtifact, $this->mockCheckpoint
        );
    }

    private function validOutput(): string
    {
        return json_encode([
            'step' => 'analyse', 'status' => 'done',
            'output' => 'OK', 'next_action' => null, 'errors' => [],
        ]);
    }

    /** Workflow à 2 agents pour les tests de retry depuis le 2ème agent */
    private function twoAgentWorkflow(): array
    {
        return [
            'name' => 'Test', 'project_path' => '/tmp/test', 'file' => 'test.yaml',
            'agents' => [
                ['id' => 'agent-one', 'engine' => 'claude-code'],
                ['id' => 'agent-two', 'engine' => 'claude-code'],
            ],
        ];
    }

    /** Checkpoint pointant sur le 2ème agent (index 1) */
    private function checkpointAtStep1(): array
    {
        return [
            'runId'           => 'test-run-id',
            'workflowFile'    => 'test.yaml',
            'brief'           => 'Mon brief',
            'completedAgents' => ['agent-one'],
            'currentAgent'    => 'agent-two',
            'currentStep'     => 1,
            'context'         => '/tmp/test-run/session.md',
        ];
    }

    /** Checkpoint pointant sur le 1er agent (index 0) — run simple */
    private function checkpointAtStep0(): array
    {
        return [
            'runId'           => 'test-run-id',
            'workflowFile'    => 'test.yaml',
            'brief'           => 'Mon brief',
            'completedAgents' => [],
            'currentAgent'    => 'agent-one',
            'currentStep'     => 0,
            'context'         => '/tmp/test-run/session.md',
        ];
    }

    private function singleAgentWorkflow(): array
    {
        return [
            'name' => 'Test', 'project_path' => '/tmp/test', 'file' => 'test.yaml',
            'agents' => [['id' => 'agent-one', 'engine' => 'claude-code']],
        ];
    }

    private function makeCliException(): CliExecutionException
    {
        return new CliExecutionException('agent-two', 1, 'stderr error');
    }

    private function setupRunPath(string $runId): void
    {
        cache()->put("run:{$runId}:path", '/tmp/test-run', 7200);
    }

    // ── Tests principaux ──────────────────────────────────────────────────────

    #[Test]
    public function it_throws_runtime_exception_when_run_path_not_in_cache(): void
    {
        $runId = 'run-no-path';
        $this->mockYaml->method('load')->willReturn($this->singleAgentWorkflow());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Run path not found');

        $this->service->executeFromCheckpoint($runId, $this->checkpointAtStep0());
    }

    #[Test]
    public function it_emits_working_status_and_resume_bubble_on_failed_agent_before_execution(): void
    {
        $runId = 'run-resume-events';
        $this->setupRunPath($runId);
        $this->mockYaml->method('load')->willReturn($this->twoAgentWorkflow());
        $this->mockDriver->method('execute')->willReturn($this->validOutput());

        $this->service->executeFromCheckpoint($runId, $this->checkpointAtStep1());

        // AgentStatusChanged(working) doit être émis sur agent-two (l'agent fautif)
        Event::assertDispatched(AgentStatusChanged::class, function (AgentStatusChanged $e) {
            return $e->agentId === 'agent-two' && $e->status === 'working';
        });

        // AgentBubble "Reprise depuis le checkpoint..." doit être émis sur agent-two
        Event::assertDispatched(AgentBubble::class, function (AgentBubble $e) {
            return $e->agentId === 'agent-two'
                && str_contains($e->message, 'Reprise depuis le checkpoint');
        });
    }

    #[Test]
    public function it_skips_agents_before_start_step(): void
    {
        $runId = 'run-skip-agents';
        $this->setupRunPath($runId);
        $this->mockYaml->method('load')->willReturn($this->twoAgentWorkflow());

        // driver->execute ne doit être appelé qu'une fois (agent-two uniquement)
        $this->mockDriver->expects($this->once())->method('execute')
            ->willReturn($this->validOutput());

        $this->service->executeFromCheckpoint($runId, $this->checkpointAtStep1());

        // agent-one ne doit pas recevoir de AgentStatusChanged(working) de la boucle
        // (il était déjà done — pas d'event 'working' pour lui)
        Event::assertNotDispatched(AgentStatusChanged::class, function (AgentStatusChanged $e) {
            return $e->agentId === 'agent-one' && $e->status === 'working';
        });
    }

    #[Test]
    public function it_emits_run_completed_when_retry_succeeds(): void
    {
        $runId = 'run-retry-success';
        $this->setupRunPath($runId);
        $this->mockYaml->method('load')->willReturn($this->twoAgentWorkflow());
        $this->mockDriver->method('execute')->willReturn($this->validOutput());

        $this->service->executeFromCheckpoint($runId, $this->checkpointAtStep1());

        Event::assertDispatched(RunCompleted::class, function (RunCompleted $e) use ($runId) {
            return $e->runId === $runId;
        });
        Event::assertNotDispatched(RunError::class);
    }

    #[Test]
    public function it_emits_run_error_when_retry_fails_again(): void
    {
        $runId = 'run-retry-fail';
        $this->setupRunPath($runId);
        $this->mockYaml->method('load')->willReturn($this->twoAgentWorkflow());
        $this->mockDriver->method('execute')->willThrowException($this->makeCliException());

        try {
            $this->service->executeFromCheckpoint($runId, $this->checkpointAtStep1());
        } catch (\Throwable) {}

        Event::assertDispatched(RunError::class, function (RunError $e) use ($runId) {
            return $e->runId === $runId && $e->agentId === 'agent-two';
        });
        Event::assertNotDispatched(RunCompleted::class);
    }

    #[Test]
    public function it_sets_done_flag_in_finally_after_success(): void
    {
        $runId = 'run-done-flag-success';
        $this->setupRunPath($runId);
        $this->mockYaml->method('load')->willReturn($this->singleAgentWorkflow());
        $this->mockDriver->method('execute')->willReturn($this->validOutput());

        $this->service->executeFromCheckpoint($runId, $this->checkpointAtStep0());

        $this->assertTrue(cache()->has("run:{$runId}:done"), 'run:{id}:done doit être posé en finally');
    }

    #[Test]
    public function it_sets_done_flag_in_finally_after_failure(): void
    {
        $runId = 'run-done-flag-failure';
        $this->setupRunPath($runId);
        $this->mockYaml->method('load')->willReturn($this->singleAgentWorkflow());
        $this->mockDriver->method('execute')->willThrowException(
            new CliExecutionException('agent-one', 1, 'err')
        );

        try {
            $this->service->executeFromCheckpoint($runId, $this->checkpointAtStep0());
        } catch (\Throwable) {}

        $this->assertTrue(cache()->has("run:{$runId}:done"), 'run:{id}:done doit être posé en finally même sur erreur');
    }

    #[Test]
    public function it_throws_run_cancelled_exception_on_cancellation(): void
    {
        $runId = 'run-cancelled-retry';
        $this->setupRunPath($runId);
        $this->mockYaml->method('load')->willReturn($this->singleAgentWorkflow());

        // Poser le flag d'annulation AVANT l'exécution
        cache()->put("run:{$runId}:cancelled", true, 300);

        $this->expectException(RunCancelledException::class);

        $this->service->executeFromCheckpoint($runId, $this->checkpointAtStep0());
    }

    #[Test]
    public function it_resumes_from_correct_step_with_two_agents_completing_successfully(): void
    {
        $runId = 'run-full-resume';
        $this->setupRunPath($runId);

        // Workflow 3 agents — checkpoint au step 1 (agent-two fautif)
        $workflow = [
            'name' => 'Test', 'project_path' => '/tmp/test', 'file' => 'test.yaml',
            'agents' => [
                ['id' => 'agent-one', 'engine' => 'claude-code'],
                ['id' => 'agent-two', 'engine' => 'claude-code'],
                ['id' => 'agent-three', 'engine' => 'claude-code'],
            ],
        ];
        $checkpoint = [
            'runId'           => $runId,
            'workflowFile'    => 'test.yaml',
            'brief'           => 'brief',
            'completedAgents' => ['agent-one'],
            'currentAgent'    => 'agent-two',
            'currentStep'     => 1,
            'context'         => '/tmp/test-run/session.md',
        ];

        $this->mockYaml->method('load')->willReturn($workflow);

        // driver appelé 2 fois : agent-two + agent-three (agent-one skippé)
        $this->mockDriver->expects($this->exactly(2))->method('execute')
            ->willReturn($this->validOutput());

        $this->service->executeFromCheckpoint($runId, $checkpoint);

        Event::assertDispatched(RunCompleted::class);
        // agent-one ne reçoit aucun AgentStatusChanged(working) de la boucle
        Event::assertNotDispatched(AgentStatusChanged::class, function (AgentStatusChanged $e) {
            return $e->agentId === 'agent-one' && $e->status === 'working';
        });
        // agent-two et agent-three reçoivent leurs events done
        Event::assertDispatched(AgentStatusChanged::class, function (AgentStatusChanged $e) {
            return $e->agentId === 'agent-two' && $e->status === 'done';
        });
        Event::assertDispatched(AgentStatusChanged::class, function (AgentStatusChanged $e) {
            return $e->agentId === 'agent-three' && $e->status === 'done';
        });
    }
}
