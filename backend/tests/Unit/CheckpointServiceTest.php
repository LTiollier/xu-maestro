<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\CheckpointService;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class CheckpointServiceTest extends TestCase
{
    private string $tmpDir;
    private CheckpointService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir  = sys_get_temp_dir() . '/checkpoint-test-' . uniqid();
        File::makeDirectory($this->tmpDir, 0755, true);
        $this->service = new CheckpointService();
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tmpDir)) {
            File::deleteDirectory($this->tmpDir);
        }
        parent::tearDown();
    }

    // ── write() ───────────────────────────────────────────────────────────────

    public function test_write_creates_checkpoint_json(): void
    {
        $this->service->write($this->tmpDir, $this->makeData());

        $this->assertFileExists($this->tmpDir . '/checkpoint.json');
    }

    public function test_write_creates_valid_json_with_correct_schema(): void
    {
        $data = $this->makeData();
        $this->service->write($this->tmpDir, $data);

        $decoded = json_decode(File::get($this->tmpDir . '/checkpoint.json'), true);

        $this->assertEquals($data['runId'], $decoded['runId']);
        $this->assertEquals($data['workflowFile'], $decoded['workflowFile']);
        $this->assertEquals($data['brief'], $decoded['brief']);
        $this->assertEquals($data['completedAgents'], $decoded['completedAgents']);
        $this->assertEquals($data['currentAgent'], $decoded['currentAgent']);
        $this->assertEquals($data['currentStep'], $decoded['currentStep']);
        $this->assertEquals($data['context'], $decoded['context']);
    }

    public function test_write_includes_completed_agents(): void
    {
        $this->service->write($this->tmpDir, $this->makeData(['completedAgents' => ['pm', 'laravel-dev']]));

        $decoded = json_decode(File::get($this->tmpDir . '/checkpoint.json'), true);

        $this->assertEquals(['pm', 'laravel-dev'], $decoded['completedAgents']);
    }

    public function test_write_overwrites_previous_checkpoint(): void
    {
        $this->service->write($this->tmpDir, $this->makeData(['currentAgent' => 'pm']));
        $this->service->write($this->tmpDir, $this->makeData(['currentAgent' => 'qa']));

        $decoded = json_decode(File::get($this->tmpDir . '/checkpoint.json'), true);
        $this->assertEquals('qa', $decoded['currentAgent']);
    }

    public function test_write_sanitises_credentials(): void
    {
        $credValue          = 'sk-supersecretapikey123456';
        $_ENV['ANTHROPIC_API_KEY'] = $credValue;

        $this->service->write($this->tmpDir, $this->makeData(['brief' => "Brief contenant: {$credValue}"]));

        $raw = File::get($this->tmpDir . '/checkpoint.json');
        $this->assertStringNotContainsString($credValue, $raw);
        $this->assertStringContainsString('[REDACTED]', $raw);

        unset($_ENV['ANTHROPIC_API_KEY']);
    }

    public function test_write_does_not_redact_non_credential_values(): void
    {
        $_ENV['APP_NAME'] = 'xu-maestro-value';

        $this->service->write($this->tmpDir, $this->makeData(['brief' => 'Brief xu-maestro-value']));

        $raw = File::get($this->tmpDir . '/checkpoint.json');
        $this->assertStringNotContainsString('[REDACTED]', $raw);
        $this->assertStringContainsString('xu-maestro-value', $raw);

        unset($_ENV['APP_NAME']);
    }

    // ── read() ────────────────────────────────────────────────────────────────

    public function test_read_returns_exact_written_data(): void
    {
        $data = $this->makeData(['completedAgents' => ['pm'], 'currentAgent' => 'dev', 'currentStep' => 1]);
        $this->service->write($this->tmpDir, $data);

        $result = $this->service->read($this->tmpDir);

        $this->assertEquals($data['runId'], $result['runId']);
        $this->assertEquals($data['completedAgents'], $result['completedAgents']);
        $this->assertEquals($data['currentAgent'], $result['currentAgent']);
        $this->assertEquals($data['currentStep'], $result['currentStep']);
    }

    public function test_read_throws_if_file_absent(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Checkpoint not found/');

        $this->service->read('/nonexistent/path/that/does/not/exist');
    }

    public function test_read_throws_if_json_invalid(): void
    {
        File::put($this->tmpDir . '/checkpoint.json', 'not-valid-json');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Invalid checkpoint JSON/');

        $this->service->read($this->tmpDir);
    }

    public function test_read_throws_if_json_is_not_array(): void
    {
        File::put($this->tmpDir . '/checkpoint.json', '"just a string"');

        $this->expectException(\RuntimeException::class);

        $this->service->read($this->tmpDir);
    }

    // ── NFR6 crash safety ─────────────────────────────────────────────────────

    public function test_write_then_read_survives_simulated_crash(): void
    {
        // Écrire checkpoint post-completion d'agentA
        $this->service->write($this->tmpDir, [
            'runId'           => 'test-run',
            'workflowFile'    => 'feature.yaml',
            'brief'           => 'Deploy feature',
            'completedAgents' => ['agent-a'],
            'currentAgent'    => 'agent-b',
            'currentStep'     => 1,
            'context'         => $this->tmpDir . '/session.md',
        ]);

        // Simuler crash → relire → état intègre
        $data = $this->service->read($this->tmpDir);

        $this->assertEquals(['agent-a'], $data['completedAgents']);
        $this->assertEquals('agent-b', $data['currentAgent']);
        $this->assertEquals(1, $data['currentStep']);
    }

    public function test_post_completion_checkpoint_includes_completed_agent(): void
    {
        // Émuler le pattern RunService: write APRÈS $completedAgents[] = $agentId
        $completedAgents = ['pm'];
        $completedAgents[] = 'laravel-dev'; // l'agent courant vient de compléter

        $this->service->write($this->tmpDir, [
            'runId'           => 'run-x',
            'workflowFile'    => 'w.yaml',
            'brief'           => 'b',
            'completedAgents' => $completedAgents,
            'currentAgent'    => 'qa',
            'currentStep'     => 2,
            'context'         => $this->tmpDir . '/session.md',
        ]);

        $result = $this->service->read($this->tmpDir);

        // completedAgents inclut 'laravel-dev' (agent qui vient de compléter)
        $this->assertContains('laravel-dev', $result['completedAgents']);
        $this->assertEquals(2, count($result['completedAgents']));
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    private function makeData(array $overrides = []): array
    {
        return array_merge([
            'runId'           => 'run-uuid',
            'workflowFile'    => 'example.yaml',
            'brief'           => 'Test brief',
            'completedAgents' => [],
            'currentAgent'    => 'pm',
            'currentStep'     => 0,
            'context'         => $this->tmpDir . '/session.md',
        ], $overrides);
    }
}
