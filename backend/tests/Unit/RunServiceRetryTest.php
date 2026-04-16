<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Drivers\DriverInterface;
use App\Drivers\DriverResolver;
use App\Events\AgentBubble;
use App\Events\AgentStatusChanged;
use App\Events\RunCompleted;
use App\Events\RunError;
use App\Exceptions\AgentTimeoutException;
use App\Exceptions\CliExecutionException;
use App\Exceptions\InvalidJsonOutputException;
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

class RunServiceRetryTest extends TestCase
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

        $this->service = new RunService(
            $this->mockResolver, $this->mockYaml, $this->mockArtifact, $this->mockCheckpoint
        );
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

    private function mandatoryWorkflow(int $maxRetries): array
    {
        return [
            'name'         => 'Test',
            'project_path' => '/tmp/test',
            'file'         => 'test.yaml',
            'agents'       => [[
                'id'          => 'agent-one',
                'engine'      => 'claude-code',
                'mandatory'   => true,
                'max_retries' => $maxRetries,
            ]],
        ];
    }

    private function nonMandatoryWorkflow(): array
    {
        return [
            'name'         => 'Test',
            'project_path' => '/tmp/test',
            'file'         => 'test.yaml',
            'agents'       => [['id' => 'agent-one', 'engine' => 'claude-code']],
        ];
    }

    private function makeCliException(): CliExecutionException
    {
        return new CliExecutionException('agent-one', 1, 'stderr error');
    }

    private function makeProcessTimedOutException(): ProcessTimedOutException
    {
        return new ProcessTimedOutException(
            $this->createMock(SymfonyProcessTimedOutException::class),
            $this->createMock(ProcessResult::class)
        );
    }

    #[Test]
    public function it_retries_mandatory_agent_on_failure_and_succeeds_on_retry(): void
    {
        $this->mockYaml->method('load')->willReturn($this->mandatoryWorkflow(2));

        $callCount = 0;
        $this->mockDriver->method('execute')
            ->willReturnCallback(function () use (&$callCount) {
                $callCount++;
                if ($callCount < 2) {
                    throw $this->makeCliException();
                }
                return $this->validOutput();
            });

        $this->service->execute('run-id', 'test.yaml', 'brief');

        $this->assertSame(2, $callCount, 'Driver doit être appelé 2 fois (1 échec + 1 succès)');
        Event::assertDispatched(RunCompleted::class);
        Event::assertDispatched(AgentBubble::class, function (AgentBubble $e) {
            return str_contains($e->message, 'Tentative 1/') && str_contains($e->message, 'échouée') && $e->agentId === 'agent-one';
        });
        Event::assertNotDispatched(RunError::class);
    }

    #[Test]
    public function it_calls_driver_exactly_n_plus_one_times_with_n_max_retries(): void
    {
        $this->mockYaml->method('load')->willReturn($this->mandatoryWorkflow(3));

        $callCount = 0;
        $this->mockDriver->expects($this->exactly(4))->method('execute')
            ->willReturnCallback(function () use (&$callCount) {
                $callCount++;
                throw $this->makeCliException();
            });

        try {
            $this->service->execute('run-id', 'test.yaml', 'brief');
        } catch (\Throwable) {}
    }

    #[Test]
    public function it_emits_run_error_only_after_exhausting_all_retries(): void
    {
        $this->mockYaml->method('load')->willReturn($this->mandatoryWorkflow(2));
        $this->mockDriver->method('execute')->willThrowException($this->makeCliException());

        try {
            $this->service->execute('run-id', 'test.yaml', 'brief');
        } catch (\Throwable) {}

        Event::assertDispatched(RunError::class, 1);
        Event::assertDispatched(AgentStatusChanged::class, function (AgentStatusChanged $e) {
            return $e->status === 'error' && $e->agentId === 'agent-one';
        });
    }

    #[Test]
    public function it_does_not_retry_non_mandatory_agent(): void
    {
        $this->mockYaml->method('load')->willReturn($this->nonMandatoryWorkflow());
        $this->mockDriver->expects($this->once())->method('execute')
            ->willThrowException($this->makeCliException());

        try {
            $this->service->execute('run-id', 'test.yaml', 'brief');
        } catch (\Throwable) {}

        Event::assertDispatched(RunError::class, 1);
        Event::assertNotDispatched(AgentBubble::class, fn (AgentBubble $e) => str_contains($e->message, 'Tentative'));
    }

    #[Test]
    public function it_does_not_retry_mandatory_agent_with_zero_max_retries(): void
    {
        $this->mockYaml->method('load')->willReturn($this->mandatoryWorkflow(0));
        $this->mockDriver->expects($this->once())->method('execute')
            ->willThrowException($this->makeCliException());

        try {
            $this->service->execute('run-id', 'test.yaml', 'brief');
        } catch (\Throwable) {}

        Event::assertDispatched(RunError::class, 1);
        Event::assertNotDispatched(AgentBubble::class, fn (AgentBubble $e) => str_contains($e->message, 'Tentative'));
    }

    #[Test]
    public function it_checks_cancellation_before_each_retry_attempt(): void
    {
        $runId = '66666666-6666-6666-6666-666666666666';
        $this->mockYaml->method('load')->willReturn($this->mandatoryWorkflow(3));

        $callCount = 0;
        $this->mockDriver->method('execute')
            ->willReturnCallback(function () use ($runId, &$callCount) {
                $callCount++;
                cache()->put("run:{$runId}:cancelled", true, 300);
                throw $this->makeCliException();
            });

        $this->expectException(RunCancelledException::class);

        try {
            $this->service->execute($runId, 'test.yaml', 'brief');
        } finally {
            $this->assertSame(1, $callCount, 'Driver ne doit pas être rappelé après cancellation');
        }
    }

    #[Test]
    public function it_retries_on_process_timed_out_exception(): void
    {
        $this->mockYaml->method('load')->willReturn($this->mandatoryWorkflow(1));

        $callCount = 0;
        $this->mockDriver->method('execute')
            ->willReturnCallback(function () use (&$callCount) {
                $callCount++;
                if ($callCount < 2) {
                    throw $this->makeProcessTimedOutException();
                }
                return $this->validOutput();
            });

        $this->service->execute('run-id', 'test.yaml', 'brief');

        $this->assertSame(2, $callCount);
        Event::assertDispatched(RunCompleted::class);
        Event::assertNotDispatched(RunError::class);
    }

    #[Test]
    public function it_retries_on_invalid_json_output_exception(): void
    {
        $this->mockYaml->method('load')->willReturn($this->mandatoryWorkflow(1));

        $callCount = 0;
        $this->mockDriver->method('execute')
            ->willReturnCallback(function () use (&$callCount) {
                $callCount++;
                if ($callCount < 2) {
                    return 'not-valid-json';
                }
                return $this->validOutput();
            });

        $this->service->execute('run-id', 'test.yaml', 'brief');

        $this->assertSame(2, $callCount);
        Event::assertDispatched(RunCompleted::class);
        Event::assertNotDispatched(RunError::class);
    }

    // P2 — max_retries fourni en string YAML → is_int() échoue, fallback à 0, aucun retry
    #[Test]
    public function it_ignores_max_retries_when_provided_as_string(): void
    {
        $workflow = [
            'name'         => 'Test',
            'project_path' => '/tmp/test',
            'file'         => 'test.yaml',
            'agents'       => [[
                'id'          => 'agent-one',
                'engine'      => 'claude-code',
                'mandatory'   => true,
                'max_retries' => '2', // string, pas int — is_int() retourne false
            ]],
        ];
        $this->mockYaml->method('load')->willReturn($workflow);
        $this->mockDriver->expects($this->once())->method('execute')
            ->willThrowException($this->makeCliException());

        try {
            $this->service->execute('run-id', 'test.yaml', 'brief');
        } catch (\Throwable) {}

        Event::assertDispatched(RunError::class, 1);
        Event::assertNotDispatched(AgentBubble::class, fn (AgentBubble $e) => str_contains($e->message, 'échouée'));
    }

    // P3 — annulation mid-retry : RunCancelledException levée, RunError non émis
    #[Test]
    public function it_does_not_emit_run_error_when_cancelled_during_retry(): void
    {
        $runId = '77777777-7777-7777-7777-777777777777';
        $this->mockYaml->method('load')->willReturn($this->mandatoryWorkflow(3));

        $this->mockDriver->method('execute')
            ->willReturnCallback(function () use ($runId) {
                cache()->put("run:{$runId}:cancelled", true, 300);
                throw $this->makeCliException();
            });

        try {
            $this->service->execute($runId, 'test.yaml', 'brief');
            $this->fail('RunCancelledException attendue');
        } catch (RunCancelledException) {
            // attendue
        }

        Event::assertNotDispatched(RunError::class);
    }

    // P4 — séquence d'exceptions mixtes sur plusieurs retries (CliException + Timeout + succès)
    #[Test]
    public function it_retries_across_different_exception_types(): void
    {
        $this->mockYaml->method('load')->willReturn($this->mandatoryWorkflow(2));

        $callCount = 0;
        $this->mockDriver->method('execute')
            ->willReturnCallback(function () use (&$callCount) {
                $callCount++;
                return match ($callCount) {
                    1       => throw $this->makeCliException(),
                    2       => throw $this->makeProcessTimedOutException(),
                    default => $this->validOutput(),
                };
            });

        $this->service->execute('run-id', 'test.yaml', 'brief');

        $this->assertSame(3, $callCount);
        Event::assertDispatched(RunCompleted::class);
        Event::assertNotDispatched(RunError::class);
    }

    // P5 — workflow 2 agents : agent 1 retry puis succès, agent 2 s'exécute normalement
    #[Test]
    public function it_executes_second_agent_normally_after_first_agent_retries(): void
    {
        $workflow = [
            'name'         => 'Multi',
            'project_path' => '/tmp/test',
            'file'         => 'multi.yaml',
            'agents'       => [
                ['id' => 'agent-1', 'engine' => 'claude-code', 'mandatory' => true, 'max_retries' => 1],
                ['id' => 'agent-2', 'engine' => 'claude-code'],
            ],
        ];
        $this->mockYaml->method('load')->willReturn($workflow);

        $callCount = 0;
        $this->mockDriver->method('execute')
            ->willReturnCallback(function () use (&$callCount) {
                $callCount++;
                if ($callCount === 1) {
                    throw $this->makeCliException(); // agent-1 tentative 1 échoue
                }
                return $this->validOutput(); // agent-1 tentative 2 + agent-2 : succès
            });

        $this->service->execute('run-id', 'multi.yaml', 'brief');

        $this->assertSame(3, $callCount, 'agent-1 x2 + agent-2 x1');
        Event::assertDispatched(RunCompleted::class, function (RunCompleted $e) {
            return $e->agentCount === 2;
        });
        Event::assertNotDispatched(RunError::class);
    }

    // P6 — AC3 pour N>2 retries : bulles émises avec bon dénominateur (X/total)
    #[Test]
    public function it_emits_retry_bubbles_with_correct_denominator_for_multiple_retries(): void
    {
        $this->mockYaml->method('load')->willReturn($this->mandatoryWorkflow(3)); // 4 tentatives total

        $callCount = 0;
        $this->mockDriver->method('execute')
            ->willReturnCallback(function () use (&$callCount) {
                $callCount++;
                if ($callCount < 4) {
                    throw $this->makeCliException();
                }
                return $this->validOutput();
            });

        $this->service->execute('run-id', 'test.yaml', 'brief');

        Event::assertDispatched(AgentBubble::class, fn (AgentBubble $e) =>
            str_contains($e->message, '1/4') && str_contains($e->message, 'échouée'));
        Event::assertDispatched(AgentBubble::class, fn (AgentBubble $e) =>
            str_contains($e->message, '2/4') && str_contains($e->message, 'échouée'));
        Event::assertDispatched(AgentBubble::class, fn (AgentBubble $e) =>
            str_contains($e->message, '3/4') && str_contains($e->message, 'échouée'));
        Event::assertDispatched(RunCompleted::class);
    }
}
