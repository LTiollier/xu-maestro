<?php

namespace Tests\Unit;

use App\Drivers\DriverInterface;
use App\Exceptions\InvalidJsonOutputException;
use App\Services\RunService;
use App\Services\YamlService;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RunServiceTest extends TestCase
{
    private DriverInterface $mockDriver;
    private YamlService $mockYaml;
    private RunService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mockDriver = $this->createMock(DriverInterface::class);
        $this->mockYaml = $this->createMock(YamlService::class);
        $this->service = new RunService($this->mockDriver, $this->mockYaml);
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
    public function it_executes_single_agent_and_returns_run_result(): void
    {
        $this->mockYaml->method('load')->willReturn($this->singleAgentWorkflow());
        $this->mockDriver->method('execute')->willReturn($this->validOutput());

        $result = $this->service->execute('test.yaml', 'Mon brief');

        $this->assertArrayHasKey('runId', $result);
        $this->assertSame('completed', $result['status']);
        $this->assertCount(1, $result['agents']);
        $this->assertSame('agent-one', $result['agents'][0]['id']);
        $this->assertSame('done', $result['agents'][0]['status']);
        $this->assertArrayHasKey('duration', $result);
        $this->assertArrayHasKey('createdAt', $result);
    }

    #[Test]
    public function it_passes_brief_as_initial_context_to_first_agent(): void
    {
        $this->mockYaml->method('load')->willReturn($this->singleAgentWorkflow());

        $this->mockDriver->expects($this->once())
            ->method('execute')
            ->with(
                '/tmp/test',
                '',
                json_encode(['brief' => 'Mon brief de test']),
                $this->anything()
            )
            ->willReturn($this->validOutput());

        $this->service->execute('test.yaml', 'Mon brief de test');
    }

    #[Test]
    public function it_passes_output_of_agent_n_as_context_of_agent_n_plus_1(): void
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
        $calls = [];

        $driver = $this->createMock(DriverInterface::class);
        $driver->expects($this->exactly(2))
            ->method('execute')
            ->willReturnCallback(function (string $projectPath, string $systemPrompt, string $context, int $timeout) use (&$calls, $output1, $output2) {
                $calls[] = $context;

                return count($calls) === 1 ? $output1 : $output2;
            });

        $yaml = $this->createMock(YamlService::class);
        $yaml->method('load')->willReturn($workflow);

        $service = new RunService($driver, $yaml);
        $service->execute('multi.yaml', 'brief');

        $this->assertSame(json_encode(['brief' => 'brief']), $calls[0]);
        $this->assertSame($output1, $calls[1]);
    }

    #[Test]
    public function it_throws_invalid_json_exception_on_non_json_output(): void
    {
        $this->mockYaml->method('load')->willReturn($this->singleAgentWorkflow());
        $this->mockDriver->method('execute')->willReturn('not-json-at-all');

        $this->expectException(InvalidJsonOutputException::class);
        $this->expectExceptionMessageMatches('/Not valid JSON/');

        $this->service->execute('test.yaml', 'brief');
    }

    #[Test]
    public function it_throws_invalid_json_exception_on_missing_required_field(): void
    {
        $this->mockYaml->method('load')->willReturn($this->singleAgentWorkflow());
        // Missing 'errors' field
        $this->mockDriver->method('execute')->willReturn(json_encode([
            'step' => 'x', 'status' => 'done', 'output' => 'y', 'next_action' => null,
        ]));

        $this->expectException(InvalidJsonOutputException::class);
        $this->expectExceptionMessageMatches('/Missing field: errors/');

        $this->service->execute('test.yaml', 'brief');
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

        $this->service->execute('test.yaml', 'brief');
    }

    #[Test]
    public function it_passes_empty_system_prompt_when_none_configured(): void
    {
        $this->mockYaml->method('load')->willReturn($this->singleAgentWorkflow());

        $this->mockDriver->expects($this->once())
            ->method('execute')
            ->with('/tmp/test', '', $this->anything(), $this->anything())
            ->willReturn($this->validOutput());

        $this->service->execute('test.yaml', 'brief');
    }

    #[Test]
    public function it_loads_system_prompt_from_file_when_inline_absent(): void
    {
        // Créer un fichier de prompt temporaire
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

        $this->service->execute('test.yaml', 'brief');

        // Nettoyage
        unlink($promptsDir . '/my-prompt.md');
        rmdir($promptsDir);
    }

    #[Test]
    public function it_returns_unique_run_id_as_uuid(): void
    {
        $this->mockYaml->method('load')->willReturn($this->singleAgentWorkflow());
        $this->mockDriver->method('execute')->willReturn($this->validOutput());

        $result1 = $this->service->execute('test.yaml', 'brief');
        $result2 = $this->service->execute('test.yaml', 'brief');

        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/',
            $result1['runId']
        );
        $this->assertNotSame($result1['runId'], $result2['runId']);
    }
}
