<?php

namespace Tests\Unit;

use App\Drivers\DriverInterface;
use App\Events\AgentBubble;
use App\Events\AgentStatusChanged;
use App\Events\RunCompleted;
use App\Exceptions\InvalidJsonOutputException;
use App\Services\ArtifactService;
use App\Services\CheckpointService;
use App\Services\RunService;
use App\Services\YamlService;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RunServiceTest extends TestCase
{
    private DriverInterface $mockDriver;
    private YamlService $mockYaml;
    private ArtifactService $mockArtifact;
    private CheckpointService $mockCheckpoint;
    private RunService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake();

        $this->mockDriver     = $this->createMock(DriverInterface::class);
        $this->mockYaml       = $this->createMock(YamlService::class);
        $this->mockArtifact   = $this->createMock(ArtifactService::class);
        $this->mockCheckpoint = $this->createMock(CheckpointService::class);

        $this->mockArtifact->method('initializeRun')->willReturn('/tmp/test-run');
        $this->mockArtifact->method('getContextContent')->willReturn('# context from session.md');

        $this->service = new RunService($this->mockDriver, $this->mockYaml, $this->mockArtifact, $this->mockCheckpoint);
    }

    private function validOutput(string $step = 'analyse', string $status = 'done'): string
    {
        return json_encode([
            'step'        => $step,
            'status'      => $status,
            'output'      => 'OK',
            'next_action' => null,
            'errors'      => [],
        ]);
    }

    private function singleAgentWorkflow(array $agentOverrides = []): array
    {
        return [
            'name'         => 'Test Workflow',
            'project_path' => '/tmp/test',
            'file'         => 'test.yaml',
            'agents'       => [array_merge([
                'id'     => 'agent-one',
                'engine' => 'claude-code',
            ], $agentOverrides)],
        ];
    }

    #[Test]
    public function it_executes_single_agent_and_emits_run_completed_event(): void
    {
        $this->mockYaml->method('load')->willReturn($this->singleAgentWorkflow());
        $this->mockDriver->method('execute')->willReturn($this->validOutput());

        $this->service->execute('test-run-id', 'test.yaml', 'Mon brief');

        Event::assertDispatched(RunCompleted::class, function (RunCompleted $e) {
            return $e->runId === 'test-run-id'
                && $e->agentCount === 1
                && $e->status === 'completed'
                && $e->runFolder === '/tmp/test-run';
        });
    }

    #[Test]
    public function it_emits_working_and_done_events_for_each_agent(): void
    {
        $this->mockYaml->method('load')->willReturn($this->singleAgentWorkflow());
        $this->mockDriver->method('execute')->willReturn($this->validOutput());

        $this->service->execute('test-run-id', 'test.yaml', 'brief');

        Event::assertDispatched(AgentStatusChanged::class, function (AgentStatusChanged $e) {
            return $e->runId === 'test-run-id'
                && $e->agentId === 'agent-one'
                && $e->status === 'working';
        });

        Event::assertDispatched(AgentStatusChanged::class, function (AgentStatusChanged $e) {
            return $e->runId === 'test-run-id'
                && $e->agentId === 'agent-one'
                && $e->status === 'done';
        });
    }

    #[Test]
    public function it_emits_bubble_event_with_agent_output_after_success(): void
    {
        $output = json_encode([
            'step'        => 'analyse',
            'status'      => 'done',
            'output'      => 'Analyse terminée avec succès.',
            'next_action' => null,
            'errors'      => [],
        ]);

        $this->mockYaml->method('load')->willReturn($this->singleAgentWorkflow());
        $this->mockDriver->method('execute')->willReturn($output);

        $this->service->execute('test-run-id', 'test.yaml', 'brief');

        Event::assertDispatched(AgentBubble::class, function (AgentBubble $e) {
            return $e->runId === 'test-run-id'
                && $e->agentId === 'agent-one'
                && $e->message === 'Analyse terminée avec succès.';
        });
    }

    #[Test]
    public function it_passes_session_md_content_as_initial_context_to_first_agent(): void
    {
        $this->mockYaml->method('load')->willReturn($this->singleAgentWorkflow());

        $this->mockDriver->expects($this->once())
            ->method('execute')
            ->with(
                '/tmp/test',
                '',
                '# context from session.md',
                $this->anything()
            )
            ->willReturn($this->validOutput());

        $this->service->execute('test-run-id', 'test.yaml', 'Mon brief de test');
    }

    #[Test]
    public function it_passes_updated_session_md_as_context_to_next_agent(): void
    {
        $workflow = [
            'name'         => 'Multi',
            'project_path' => '/tmp/test',
            'file'         => 'multi.yaml',
            'agents'       => [
                ['id' => 'agent-1', 'engine' => 'claude-code'],
                ['id' => 'agent-2', 'engine' => 'claude-code'],
            ],
        ];

        $output1 = $this->validOutput('step-1');
        $output2 = $this->validOutput('step-2');
        $calls   = [];

        $driver = $this->createMock(DriverInterface::class);
        $driver->expects($this->exactly(2))
            ->method('execute')
            ->willReturnCallback(function (string $projectPath, string $systemPrompt, string $context, int $timeout) use (&$calls, $output1, $output2) {
                $calls[] = $context;

                return count($calls) === 1 ? $output1 : $output2;
            });

        $yaml = $this->createMock(YamlService::class);
        $yaml->method('load')->willReturn($workflow);

        $mockArtifact = $this->createMock(ArtifactService::class);
        $mockArtifact->method('initializeRun')->willReturn('/tmp/test-run');
        $mockArtifact->expects($this->exactly(3))
            ->method('getContextContent')
            ->willReturnOnConsecutiveCalls(
                '# initial session content',
                '# session content after agent-1',
                '# session content after agent-2'
            );

        $mockCheckpoint = $this->createMock(CheckpointService::class);
        $service        = new RunService($driver, $yaml, $mockArtifact, $mockCheckpoint);
        $service->execute('test-run-id', 'multi.yaml', 'brief');

        $this->assertSame('# initial session content', $calls[0]);
        $this->assertSame('# session content after agent-1', $calls[1]);
    }

    #[Test]
    public function it_throws_invalid_json_exception_on_non_json_output(): void
    {
        $this->mockYaml->method('load')->willReturn($this->singleAgentWorkflow());
        $this->mockDriver->method('execute')->willReturn('not-json-at-all');

        $this->expectException(InvalidJsonOutputException::class);
        $this->expectExceptionMessageMatches('/Not valid JSON/');

        $this->service->execute('test-run-id', 'test.yaml', 'brief');
    }

    #[Test]
    public function it_throws_invalid_json_exception_on_missing_required_field(): void
    {
        $this->mockYaml->method('load')->willReturn($this->singleAgentWorkflow());
        $this->mockDriver->method('execute')->willReturn(json_encode([
            'step' => 'x', 'status' => 'done', 'output' => 'y', 'next_action' => null,
        ]));

        $this->expectException(InvalidJsonOutputException::class);
        $this->expectExceptionMessageMatches('/Missing field: errors/');

        $this->service->execute('test-run-id', 'test.yaml', 'brief');
    }

    #[Test]
    public function it_injects_inline_system_prompt_into_driver(): void
    {
        $workflow = $this->singleAgentWorkflow(['system_prompt' => 'Tu es un agent de test.']);
        $this->mockYaml->method('load')->willReturn($workflow);

        $this->mockDriver->expects($this->once())
            ->method('execute')
            ->with('/tmp/test', 'Tu es un agent de test.', $this->anything(), $this->anything())
            ->willReturn($this->validOutput());

        $this->service->execute('test-run-id', 'test.yaml', 'brief');
    }

    #[Test]
    public function it_passes_empty_system_prompt_when_none_configured(): void
    {
        $this->mockYaml->method('load')->willReturn($this->singleAgentWorkflow());

        $this->mockDriver->expects($this->once())
            ->method('execute')
            ->with('/tmp/test', '', $this->anything(), $this->anything())
            ->willReturn($this->validOutput());

        $this->service->execute('test-run-id', 'test.yaml', 'brief');
    }

    #[Test]
    public function it_loads_system_prompt_from_file_when_inline_absent(): void
    {
        $promptsDir = sys_get_temp_dir() . '/xu-prompts-test-' . uniqid();
        mkdir($promptsDir, 0755, true);
        file_put_contents($promptsDir . '/my-prompt.md', 'Contenu du prompt depuis fichier.');

        config(['xu-workflow.prompts_path' => $promptsDir]);

        $workflow = $this->singleAgentWorkflow(['system_prompt_file' => 'my-prompt.md']);
        $this->mockYaml->method('load')->willReturn($workflow);

        $this->mockDriver->expects($this->once())
            ->method('execute')
            ->with('/tmp/test', 'Contenu du prompt depuis fichier.', $this->anything(), $this->anything())
            ->willReturn($this->validOutput());

        $this->service->execute('test-run-id', 'test.yaml', 'brief');

        unlink($promptsDir . '/my-prompt.md');
        rmdir($promptsDir);
    }

    #[Test]
    public function it_calls_artifact_service_initialize_run_once(): void
    {
        $this->mockYaml->method('load')->willReturn($this->singleAgentWorkflow());
        $this->mockDriver->method('execute')->willReturn($this->validOutput());

        $this->mockArtifact->expects($this->once())->method('initializeRun');

        $this->service->execute('test-run-id', 'test.yaml', 'brief');
    }

    #[Test]
    public function it_writes_post_completion_checkpoint_with_agent_before_done_event(): void
    {
        $this->mockYaml->method('load')->willReturn($this->singleAgentWorkflow());
        $this->mockDriver->method('execute')->willReturn($this->validOutput());

        $writtenData = [];
        $this->mockCheckpoint
            ->expects($this->exactly(2))
            ->method('write')
            ->willReturnCallback(function (string $runPath, array $data) use (&$writtenData) {
                $writtenData[] = $data;
            });

        $this->service->execute('test-run-id', 'test.yaml', 'brief');

        // 1ère écriture (pré-agent) : completedAgents ne contient pas encore l'agent
        $this->assertEquals([], $writtenData[0]['completedAgents']);
        $this->assertEquals('agent-one', $writtenData[0]['currentAgent']);
        $this->assertEquals(0, $writtenData[0]['currentStep']);

        // 2ème écriture (post-completion, NFR6) : completedAgents inclut l'agent
        $this->assertContains('agent-one', $writtenData[1]['completedAgents']);
        $this->assertNull($writtenData[1]['currentAgent']); // dernier agent → next = null
        $this->assertEquals(1, $writtenData[1]['currentStep']); // $stepIndex + 1

        // L'événement 'done' est bien émis après (code séquentiel : write → event)
        Event::assertDispatched(AgentStatusChanged::class, function (AgentStatusChanged $e) {
            return $e->status === 'done' && $e->agentId === 'agent-one';
        });
    }

    #[Test]
    public function it_calls_append_agent_output_for_each_agent(): void
    {
        $workflow = [
            'name'         => 'Multi',
            'project_path' => '/tmp/test',
            'file'         => 'multi.yaml',
            'agents'       => [
                ['id' => 'agent-1', 'engine' => 'claude-code'],
                ['id' => 'agent-2', 'engine' => 'claude-code'],
            ],
        ];
        $this->mockYaml->method('load')->willReturn($workflow);
        $this->mockDriver->method('execute')->willReturn($this->validOutput());

        $this->mockArtifact->expects($this->exactly(2))->method('appendAgentOutput');

        $this->service->execute('test-run-id', 'multi.yaml', 'brief');
    }
}
