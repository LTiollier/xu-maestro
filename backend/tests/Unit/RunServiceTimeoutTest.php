<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Drivers\DriverInterface;
use App\Drivers\DriverResolver;
use App\Exceptions\AgentTimeoutException;
use App\Exceptions\RunCancelledException;
use App\Services\ArtifactService;
use App\Services\CheckpointService;
use App\Services\RunService;
use App\Services\YamlService;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Exception\ProcessTimedOutException as SymfonyProcessTimedOutException;
use Tests\TestCase;

class RunServiceTimeoutTest extends TestCase
{
    private DriverInterface $mockDriver;
    private DriverResolver $mockResolver;
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
        $this->mockResolver   = $this->createMock(DriverResolver::class);
        $this->mockResolver->method('for')->willReturn($this->mockDriver);
        $this->mockYaml       = $this->createMock(YamlService::class);
        $this->mockArtifact   = $this->createMock(ArtifactService::class);
        $this->mockCheckpoint = $this->createMock(CheckpointService::class);

        $this->mockArtifact->method('initializeRun')->willReturn('/tmp/test-run');
        $this->mockArtifact->method('getContextContent')->willReturn('# context');

        $this->service = new RunService($this->mockResolver, $this->mockYaml, $this->mockArtifact, $this->mockCheckpoint);
    }

    private function validOutput(): string
    {
        return json_encode([
            'step'        => 'analyse',
            'status'      => 'done',
            'output'      => 'OK',
            'next_action' => null,
            'errors'      => [],
        ]);
    }

    private function makeProcessTimedOutException(): ProcessTimedOutException
    {
        $symfonyException = $this->createMock(SymfonyProcessTimedOutException::class);
        $processResult    = $this->createMock(ProcessResult::class);

        return new ProcessTimedOutException($symfonyException, $processResult);
    }

    private function workflowWithAgentTimeout(int $timeout): array
    {
        return [
            'name'         => 'Test',
            'project_path' => '/tmp/test',
            'file'         => 'test.yaml',
            'agents'       => [[
                'id'      => 'agent-one',
                'engine'  => 'claude-code',
                'timeout' => $timeout,
            ]],
        ];
    }

    private function workflowWithoutTimeout(): array
    {
        return [
            'name'         => 'Test',
            'project_path' => '/tmp/test',
            'file'         => 'test.yaml',
            'agents'       => [[
                'id'     => 'agent-one',
                'engine' => 'claude-code',
            ]],
        ];
    }

    private function twoAgentWorkflow(): array
    {
        return [
            'name'         => 'Multi',
            'project_path' => '/tmp/test',
            'file'         => 'multi.yaml',
            'agents'       => [
                ['id' => 'agent-1', 'engine' => 'claude-code'],
                ['id' => 'agent-2', 'engine' => 'claude-code'],
            ],
        ];
    }

    // ── Timeout par agent ────────────────────────────────────────────────────

    #[Test]
    public function it_passes_yaml_timeout_to_driver(): void
    {
        $this->mockYaml->method('load')->willReturn($this->workflowWithAgentTimeout(45));

        $this->mockDriver->expects($this->once())
            ->method('execute')
            ->with(
                $this->anything(),
                $this->anything(),
                $this->anything(),
                45
            )
            ->willReturn($this->validOutput());

        $this->service->execute('test-run-id', 'test.yaml', 'brief');
    }

    #[Test]
    public function it_uses_default_timeout_when_yaml_timeout_absent(): void
    {
        config(['xu-workflow.default_timeout' => 90]);
        $this->mockYaml->method('load')->willReturn($this->workflowWithoutTimeout());

        $this->mockDriver->expects($this->once())
            ->method('execute')
            ->with(
                $this->anything(),
                $this->anything(),
                $this->anything(),
                90
            )
            ->willReturn($this->validOutput());

        $this->service->execute('test-run-id', 'test.yaml', 'brief');
    }

    #[Test]
    public function it_converts_process_timed_out_exception_to_agent_timeout_exception(): void
    {
        $this->mockYaml->method('load')->willReturn($this->workflowWithAgentTimeout(30));
        $this->mockDriver->method('execute')
            ->willThrowException($this->makeProcessTimedOutException());

        try {
            $this->service->execute('test-run-id', 'test.yaml', 'brief');
            $this->fail('AgentTimeoutException not thrown');
        } catch (AgentTimeoutException $e) {
            $this->assertSame('agent-one', $e->agentId);
            $this->assertSame(30, $e->timeout);
            $this->assertStringContainsString("'agent-one'", $e->getMessage());
            $this->assertStringContainsString('30 seconds', $e->getMessage());
        }
    }

    // ── Annulation inter-agents ─────────────────────────────────────────────

    #[Test]
    public function it_stops_before_first_agent_when_cancellation_flag_set(): void
    {
        $runId = '11111111-1111-1111-1111-111111111111';
        cache()->put("run:{$runId}:cancelled", true, 300);

        $this->mockYaml->method('load')->willReturn($this->workflowWithoutTimeout());
        $this->mockDriver->expects($this->never())->method('execute');

        $this->expectException(RunCancelledException::class);
        $this->expectExceptionMessageMatches("/{$runId}/");

        $this->service->execute($runId, 'test.yaml', 'brief');
    }

    #[Test]
    public function it_stops_before_second_agent_when_cancellation_flag_set_after_first(): void
    {
        $runId = '22222222-2222-2222-2222-222222222222';

        $this->mockYaml->method('load')->willReturn($this->twoAgentWorkflow());

        $callCount = 0;
        $this->mockDriver->method('execute')
            ->willReturnCallback(function () use ($runId, &$callCount) {
                $callCount++;
                cache()->put("run:{$runId}:cancelled", true, 300);

                return $this->validOutput();
            });

        $this->expectException(RunCancelledException::class);
        $this->expectExceptionMessage($runId);

        try {
            $this->service->execute($runId, 'multi.yaml', 'brief');
        } finally {
            $this->assertSame(1, $callCount, 'Only first agent should have run');
        }
    }

    // ── Nettoyage cache (NFR4) ──────────────────────────────────────────────

    #[Test]
    public function it_cleans_up_cache_after_successful_run(): void
    {
        $runId = '33333333-3333-3333-3333-333333333333';

        $this->mockYaml->method('load')->willReturn($this->workflowWithoutTimeout());
        $this->mockDriver->method('execute')->willReturn($this->validOutput());

        $this->service->execute($runId, 'test.yaml', 'brief');

        $this->assertNull(cache()->get("run:{$runId}"));
        $this->assertNull(cache()->get("run:{$runId}:cancelled"));
        $this->assertTrue(cache()->get("run:{$runId}:done"));
    }

    #[Test]
    public function it_cleans_up_cache_after_timeout(): void
    {
        $runId = '44444444-4444-4444-4444-444444444444';

        $this->mockYaml->method('load')->willReturn($this->workflowWithAgentTimeout(10));
        $this->mockDriver->method('execute')
            ->willThrowException($this->makeProcessTimedOutException());

        try {
            $this->service->execute($runId, 'test.yaml', 'brief');
        } catch (AgentTimeoutException) {
            // Expected
        }

        $this->assertNull(cache()->get("run:{$runId}"));
        $this->assertNull(cache()->get("run:{$runId}:cancelled"));
    }

    #[Test]
    public function it_registers_active_run_in_cache_before_driver_is_called(): void
    {
        $runId = '55555555-5555-5555-5555-555555555555';

        $this->mockYaml->method('load')->willReturn($this->workflowWithoutTimeout());

        $runRegisteredBeforeExecution = false;
        $this->mockDriver->method('execute')
            ->willReturnCallback(function () use ($runId, &$runRegisteredBeforeExecution) {
                $runRegisteredBeforeExecution = cache()->has("run:{$runId}");

                return $this->validOutput();
            });

        $this->service->execute($runId, 'test.yaml', 'brief');

        $this->assertTrue($runRegisteredBeforeExecution, 'Run should be registered in cache before driver execution');
    }
}
