<?php

namespace Tests\Unit;

use App\Services\ArtifactService;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class ArtifactServiceTest extends TestCase
{
    private string $tmpBase;
    private ArtifactService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpBase = sys_get_temp_dir() . '/artifact-test-' . uniqid();
        config(['xu-workflow.runs_path' => $this->tmpBase]);
        $this->service = new ArtifactService();
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tmpBase)) {
            File::deleteDirectory($this->tmpBase);
        }
        parent::tearDown();
    }

    // ── initializeRun ─────────────────────────────────────────────────────────

    public function test_initialize_run_creates_session_md(): void
    {
        $runPath = $this->service->initializeRun('run-uuid', 'example.yaml', 'My brief');

        $this->assertFileExists($runPath . '/session.md');
    }

    public function test_initialize_run_creates_checkpoint_json(): void
    {
        $runPath = $this->service->initializeRun('run-uuid', 'example.yaml', 'My brief');

        $this->assertFileExists($runPath . '/checkpoint.json');
    }

    public function test_initialize_run_creates_agents_directory(): void
    {
        $runPath = $this->service->initializeRun('run-uuid', 'example.yaml', 'My brief');

        $this->assertDirectoryExists($runPath . '/agents');
    }

    public function test_initialize_run_session_md_contains_header(): void
    {
        $runPath = $this->service->initializeRun('run-uuid-123', 'workflow.yaml', 'Test brief');

        $content = file_get_contents($runPath . '/session.md');
        $this->assertStringContainsString('run-uuid-123', $content);
        $this->assertStringContainsString('workflow.yaml', $content);
        $this->assertStringContainsString('Test brief', $content);
    }

    public function test_initialize_run_checkpoint_json_has_correct_schema(): void
    {
        $runPath = $this->service->initializeRun('run-uuid-456', 'feature.yaml', 'Deploy feature');

        $checkpoint = json_decode(file_get_contents($runPath . '/checkpoint.json'), true);

        $this->assertEquals('run-uuid-456', $checkpoint['runId']);
        $this->assertEquals('feature.yaml', $checkpoint['workflowFile']);
        $this->assertEquals('Deploy feature', $checkpoint['brief']);
        $this->assertEquals([], $checkpoint['completedAgents']);
        $this->assertNull($checkpoint['currentAgent']);
        $this->assertEquals(0, $checkpoint['currentStep']);
        $this->assertStringContainsString('session.md', $checkpoint['context']);
    }

    public function test_initialize_run_returns_run_path(): void
    {
        $runPath = $this->service->initializeRun('run-uuid', 'example.yaml', 'Brief');

        $this->assertStringStartsWith($this->tmpBase, $runPath);
        $this->assertDirectoryExists($runPath);
    }

    // ── appendAgentOutput ─────────────────────────────────────────────────────

    public function test_append_agent_output_appends_to_session_md(): void
    {
        $runPath = $this->service->initializeRun('run-uuid', 'example.yaml', 'Brief');
        $initialContent = file_get_contents($runPath . '/session.md');

        $this->service->appendAgentOutput($runPath, 'agent-pm', '{"step":1,"status":"done","output":"Result","next_action":"continue","errors":[]}');

        $newContent = file_get_contents($runPath . '/session.md');
        $this->assertGreaterThan(strlen($initialContent), strlen($newContent));
        $this->assertStringContainsString('agent-pm', $newContent);
    }

    public function test_append_agent_output_is_append_only(): void
    {
        $runPath = $this->service->initializeRun('run-uuid', 'example.yaml', 'Brief');

        $this->service->appendAgentOutput($runPath, 'agent-1', 'output-1');
        $this->service->appendAgentOutput($runPath, 'agent-2', 'output-2');

        $content = file_get_contents($runPath . '/session.md');
        $this->assertStringContainsString('agent-1', $content);
        $this->assertStringContainsString('output-1', $content);
        $this->assertStringContainsString('agent-2', $content);
        $this->assertStringContainsString('output-2', $content);

        // L'ordre doit être préservé
        $this->assertLessThan(strpos($content, 'agent-2'), strpos($content, 'agent-1'));
    }

    public function test_append_agent_output_creates_agent_file(): void
    {
        $runPath = $this->service->initializeRun('run-uuid', 'example.yaml', 'Brief');

        $this->service->appendAgentOutput($runPath, 'pm-agent', 'agent output content');

        $this->assertFileExists($runPath . '/agents/pm-agent.md');
        $this->assertStringContainsString('agent output content', file_get_contents($runPath . '/agents/pm-agent.md'));
    }

    // ── writeCheckpoint ───────────────────────────────────────────────────────

    public function test_write_checkpoint_creates_valid_json(): void
    {
        $runPath = $this->service->initializeRun('run-uuid', 'example.yaml', 'Brief');
        $data = [
            'runId'           => 'run-uuid',
            'workflowFile'    => 'example.yaml',
            'brief'           => 'Brief',
            'completedAgents' => ['pm'],
            'currentAgent'    => 'dev',
            'currentStep'     => 0,
            'context'         => $runPath . '/session.md',
        ];

        $this->service->writeCheckpoint($runPath, $data);

        $checkpoint = json_decode(file_get_contents($runPath . '/checkpoint.json'), true);
        $this->assertEquals(['pm'], $checkpoint['completedAgents']);
        $this->assertEquals('dev', $checkpoint['currentAgent']);
    }

    // ── getContextContent ─────────────────────────────────────────────────────

    public function test_get_context_content_returns_session_md_content(): void
    {
        $runPath = $this->service->initializeRun('run-uuid', 'example.yaml', 'Brief');
        $this->service->appendAgentOutput($runPath, 'agent-1', 'output content here');

        $context = $this->service->getContextContent($runPath);

        $this->assertStringContainsString('agent-1', $context);
        $this->assertStringContainsString('output content here', $context);
    }

    // ── sanitizeEnvCredentials ────────────────────────────────────────────────

    public function test_sanitize_redacts_credential_env_var_values(): void
    {
        // Simuler une variable d'environnement de type credential
        $credValue = 'sk-supersecretapikey123456';
        $_ENV['ANTHROPIC_API_KEY'] = $credValue;

        $runPath = $this->service->initializeRun('run-uuid', 'example.yaml', 'Brief');

        $content = "Output contient la clé: {$credValue} et autres infos";
        $this->service->appendAgentOutput($runPath, 'agent-1', $content);

        $written = file_get_contents($runPath . '/agents/agent-1.md');
        $this->assertStringNotContainsString($credValue, $written);
        $this->assertStringContainsString('[REDACTED]', $written);

        unset($_ENV['ANTHROPIC_API_KEY']);
    }

    public function test_sanitize_does_not_redact_short_values(): void
    {
        $_ENV['SOME_API_VAR'] = 'short';  // < 8 chars

        $runPath = $this->service->initializeRun('run-uuid', 'example.yaml', 'Brief');

        $content = 'Output with short in it';
        $this->service->appendAgentOutput($runPath, 'agent-1', $content);

        $written = file_get_contents($runPath . '/agents/agent-1.md');
        $this->assertStringNotContainsString('[REDACTED]', $written);
        $this->assertStringContainsString('short', $written);

        unset($_ENV['SOME_API_VAR']);
    }

    public function test_sanitize_does_not_redact_non_credential_vars(): void
    {
        $_ENV['APP_NAME'] = 'xu-workflow-app';  // nom ne contient pas key|token|etc.

        $runPath = $this->service->initializeRun('run-uuid', 'example.yaml', 'Brief');

        $content = 'Output with xu-workflow-app in it';
        $this->service->appendAgentOutput($runPath, 'agent-1', $content);

        $written = file_get_contents($runPath . '/agents/agent-1.md');
        $this->assertStringNotContainsString('[REDACTED]', $written);
        $this->assertStringContainsString('xu-workflow-app', $written);

        unset($_ENV['APP_NAME']);
    }
}
