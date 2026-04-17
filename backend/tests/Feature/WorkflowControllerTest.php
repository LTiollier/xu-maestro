<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\ScaffoldException;
use App\Services\WorkflowScaffolderService;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class WorkflowControllerTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();

        // Dossier temporaire pour les YAML de test
        $this->tmpDir = sys_get_temp_dir() . '/xu-maestro-test-' . uniqid();
        mkdir($this->tmpDir, 0755, true);

        Config::set('xu-maestro.workflows_path', $this->tmpDir);
    }

    protected function tearDown(): void
    {
        try {
            foreach (glob($this->tmpDir . '/*.yaml') ?: [] as $file) {
                unlink($file);
            }
            if (is_dir($this->tmpDir)) {
                rmdir($this->tmpDir);
            }
        } finally {
            parent::tearDown();
        }
    }

    #[Test]
    public function get_workflows_returns_200_with_array(): void
    {
        file_put_contents($this->tmpDir . '/simple.yaml', <<<YAML
name: "Simple Workflow"
project_path: "/tmp/test"
agents:
  - id: agent-one
    engine: claude-code
    timeout: 60
    steps:
      - "Étape 1"
    system_prompt: "Tu es un agent."
YAML);

        $response = $this->getJson('/api/workflows');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            ['name', 'file', 'agents'],
        ]);
        $response->assertJsonCount(1);
        $response->assertJsonPath('0.name', 'Simple Workflow');
        $response->assertJsonPath('0.file', 'simple.yaml');
    }

    #[Test]
    public function response_has_no_data_wrapper(): void
    {
        file_put_contents($this->tmpDir . '/workflow.yaml', <<<YAML
name: "Test"
project_path: "/tmp"
agents:
  - id: test-agent
    engine: claude-code
    timeout: 30
YAML);

        $response = $this->getJson('/api/workflows');

        $response->assertStatus(200);

        // Le tableau doit être à la racine, pas dans {"data": [...]}
        $this->assertIsArray($response->json());
        $this->assertArrayNotHasKey('data', $response->json());
    }

    #[Test]
    public function malformed_yaml_is_excluded_other_workflows_returned(): void
    {
        // YAML valide
        file_put_contents($this->tmpDir . '/valid.yaml', <<<YAML
name: "Valid Workflow"
project_path: "/tmp"
agents:
  - id: agent-a
    engine: claude-code
    timeout: 60
YAML);

        // YAML malformé (syntaxe invalide)
        file_put_contents($this->tmpDir . '/broken.yaml', "name: \"Broken\n  agents: [invalid: yaml: here");

        $response = $this->getJson('/api/workflows');

        $response->assertStatus(200);
        $response->assertJsonCount(1);
        $response->assertJsonPath('0.name', 'Valid Workflow');
    }

    #[Test]
    public function yaml_missing_required_fields_is_excluded(): void
    {
        // Manque le champ 'name'
        file_put_contents($this->tmpDir . '/no-name.yaml', <<<YAML
project_path: "/tmp"
agents:
  - id: agent-a
    engine: claude-code
    timeout: 60
YAML);

        // Manque le champ 'agents'
        file_put_contents($this->tmpDir . '/no-agents.yaml', <<<YAML
name: "No Agents"
project_path: "/tmp"
YAML);

        $response = $this->getJson('/api/workflows');

        $response->assertStatus(200);
        $response->assertJsonCount(0);
    }

    #[Test]
    public function agent_fields_are_returned_correctly(): void
    {
        file_put_contents($this->tmpDir . '/agents.yaml', <<<YAML
name: "Multi Agent"
project_path: "/tmp"
agents:
  - id: pm-agent
    engine: claude-code
    timeout: 90
  - id: dev-agent
    engine: gemini-cli
    timeout: 120
YAML);

        $response = $this->getJson('/api/workflows');

        $response->assertStatus(200);
        $response->assertJsonPath('0.agents.0.id', 'pm-agent');
        $response->assertJsonPath('0.agents.0.engine', 'claude-code');
        $response->assertJsonPath('0.agents.0.timeout', 90);
        $response->assertJsonPath('0.agents.1.id', 'dev-agent');
        $response->assertJsonPath('0.agents.1.engine', 'gemini-cli');
    }

    #[Test]
    public function agent_without_timeout_uses_default(): void
    {
        file_put_contents($this->tmpDir . '/no-timeout.yaml', <<<YAML
name: "No Timeout Workflow"
project_path: "/tmp"
agents:
  - id: agent-default
    engine: claude-code
YAML);

        $response = $this->getJson('/api/workflows');

        $response->assertStatus(200);
        // default_timeout = 120 depuis config/xu-maestro.php
        $response->assertJsonPath('0.agents.0.timeout', config('xu-maestro.default_timeout'));
    }

    #[Test]
    public function steps_are_exposed_and_system_prompt_is_not(): void
    {
        file_put_contents($this->tmpDir . '/full.yaml', <<<YAML
name: "Full Workflow"
project_path: "/tmp"
agents:
  - id: agent-one
    engine: claude-code
    timeout: 60
    steps:
      - "Step 1"
      - "Step 2"
    system_prompt: "Confidentiel"
YAML);

        $response = $this->getJson('/api/workflows');

        $response->assertStatus(200);

        // steps[] doit être exposé (requis par Story 1.5 pour l'affichage AgentCard)
        $response->assertJsonPath('0.agents.0.steps', ['Step 1', 'Step 2']);

        // system_prompt ne doit jamais être exposé (secret)
        $agent = $response->json('0.agents.0');
        $this->assertArrayNotHasKey('system_prompt', $agent);
        $this->assertArrayNotHasKey('systemPrompt', $agent);
    }

    #[Test]
    public function agent_without_steps_returns_empty_array(): void
    {
        file_put_contents($this->tmpDir . '/no-steps.yaml', <<<YAML
name: "No Steps Workflow"
project_path: "/tmp"
agents:
  - id: agent-a
    engine: claude-code
    timeout: 60
YAML);

        $response = $this->getJson('/api/workflows');

        $response->assertStatus(200);
        $response->assertJsonPath('0.agents.0.steps', []);
    }

    #[Test]
    public function agent_with_string_timeout_is_cast_to_int(): void
    {
        file_put_contents($this->tmpDir . '/str-timeout.yaml', <<<YAML
name: "String Timeout"
project_path: "/tmp"
agents:
  - id: agent-a
    engine: claude-code
    timeout: "90"
YAML);

        $response = $this->getJson('/api/workflows');

        $response->assertStatus(200);
        $this->assertIsInt($response->json('0.agents.0.timeout'));
        $response->assertJsonPath('0.agents.0.timeout', 90);
    }

    #[Test]
    public function agent_with_empty_id_is_excluded(): void
    {
        file_put_contents($this->tmpDir . '/empty-id.yaml', <<<YAML
name: "Empty ID"
project_path: "/tmp"
agents:
  - id: ""
    engine: claude-code
    timeout: 60
YAML);

        $response = $this->getJson('/api/workflows');

        $response->assertStatus(200);
        $response->assertJsonCount(0);
    }

    #[Test]
    public function null_workflows_path_returns_empty_array(): void
    {
        Config::set('xu-maestro.workflows_path', null);

        $response = $this->getJson('/api/workflows');

        $response->assertStatus(200);
        $response->assertExactJson([]);
    }

    #[Test]
    public function empty_workflows_folder_returns_empty_array(): void
    {
        $response = $this->getJson('/api/workflows');

        $response->assertStatus(200);
        $response->assertExactJson([]);
    }

    // --- generate() ---

    #[Test]
    public function generate_returns_422_when_brief_is_too_short(): void
    {
        $response = $this->postJson('/api/workflows/generate', ['brief' => 'short']);

        $response->assertStatus(422);
        $response->assertJsonValidationErrorFor('brief');
    }

    #[Test]
    public function generate_returns_200_with_yaml_and_parsed(): void
    {
        $yaml = implode("\n", [
            'name: "Test Workflow"',
            'project_path: ""',
            'agents:',
            '  - id: test-agent',
            '    engine: claude-code',
            '    steps:',
            '      - "Do something"',
        ]);

        $this->mock(WorkflowScaffolderService::class)
             ->shouldReceive('scaffold')
             ->once()
             ->andReturn([
                 'yaml'   => $yaml,
                 'parsed' => [
                     'name'         => 'Test Workflow',
                     'project_path' => '',
                     'agents'       => [['id' => 'test-agent', 'engine' => 'claude-code', 'steps' => ['Do something']]],
                 ],
             ]);

        $response = $this->postJson('/api/workflows/generate', ['brief' => 'A valid brief that is long enough to pass validation']);

        $response->assertStatus(200);
        $response->assertJsonStructure(['yaml', 'parsed']);
        $response->assertJsonPath('parsed.name', 'Test Workflow');
    }

    #[Test]
    public function generate_returns_422_on_scaffold_exception_with_raw_yaml(): void
    {
        $this->mock(WorkflowScaffolderService::class)
             ->shouldReceive('scaffold')
             ->once()
             ->andThrow(new ScaffoldException('YAML invalide : unexpected token', 'bad: yaml: ['));

        $response = $this->postJson('/api/workflows/generate', ['brief' => 'A valid brief that is long enough']);

        $response->assertStatus(422);
        $response->assertJsonStructure(['error', 'raw_yaml']);
        $response->assertJsonPath('raw_yaml', 'bad: yaml: [');
        $this->assertStringContainsString('YAML invalide', $response->json('error'));
    }

    // --- store() ---

    #[Test]
    public function store_creates_file_and_returns_201(): void
    {
        $yaml = implode("\n", [
            'name: "New Workflow"',
            'project_path: "/tmp/project"',
            'agents:',
            '  - id: test-agent',
            '    engine: claude-code',
            '    steps:',
            '      - "Do something"',
        ]);

        $response = $this->postJson('/api/workflows', [
            'filename'     => 'new-workflow',
            'yaml_content' => $yaml,
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('name', 'New Workflow');
        $response->assertJsonPath('file', 'new-workflow.yaml');
        $this->assertFileExists($this->tmpDir . '/new-workflow.yaml');
    }

    #[Test]
    public function store_response_has_no_data_wrapper(): void
    {
        $yaml = implode("\n", [
            'name: "Flat Response"',
            'project_path: "/tmp/project"',
            'agents:',
            '  - id: flat-agent',
            '    engine: claude-code',
            '    steps:',
            '      - "Step"',
        ]);

        $response = $this->postJson('/api/workflows', [
            'filename'     => 'flat-response',
            'yaml_content' => $yaml,
        ]);

        $response->assertStatus(201);
        $this->assertArrayHasKey('name', $response->json());
        $this->assertArrayNotHasKey('data', $response->json());
    }

    #[Test]
    public function store_returns_422_when_filename_is_missing(): void
    {
        $response = $this->postJson('/api/workflows', [
            'yaml_content' => 'name: test',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrorFor('filename');
    }

    #[Test]
    public function store_returns_422_when_filename_has_invalid_characters(): void
    {
        $yaml = implode("\n", [
            'name: "Test"',
            'project_path: "/tmp"',
            'agents:',
            '  - id: test-agent',
            '    engine: claude-code',
        ]);

        $response = $this->postJson('/api/workflows', [
            'filename'     => 'Invalid_Filename',
            'yaml_content' => $yaml,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrorFor('filename');
    }

    #[Test]
    public function store_returns_422_when_yaml_is_malformed(): void
    {
        $response = $this->postJson('/api/workflows', [
            'filename'     => 'test-workflow',
            'yaml_content' => "name: \"broken\n  invalid: [yaml",
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('YAML invalide', $response->json('error'));
    }

    #[Test]
    public function store_returns_422_when_project_path_is_empty(): void
    {
        $yaml = implode("\n", [
            'name: "Test"',
            'project_path: ""',
            'agents:',
            '  - id: test-agent',
            '    engine: claude-code',
            '    steps:',
            '      - "Do something"',
        ]);

        $response = $this->postJson('/api/workflows', [
            'filename'     => 'test-workflow',
            'yaml_content' => $yaml,
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error', 'Le champ project_path est requis avant de sauvegarder');
    }

    #[Test]
    public function store_returns_422_when_project_path_is_whitespace_only(): void
    {
        $yaml = implode("\n", [
            'name: "Test"',
            'project_path: "   "',
            'agents:',
            '  - id: test-agent',
            '    engine: claude-code',
            '    steps:',
            '      - "Do something"',
        ]);

        $response = $this->postJson('/api/workflows', [
            'filename'     => 'test-workflow',
            'yaml_content' => $yaml,
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error', 'Le champ project_path est requis avant de sauvegarder');
    }

    #[Test]
    public function store_returns_422_when_structure_is_invalid(): void
    {
        $yaml = implode("\n", [
            'name: "Missing Agents"',
            'project_path: "/tmp"',
        ]);

        $response = $this->postJson('/api/workflows', [
            'filename'     => 'missing-agents',
            'yaml_content' => $yaml,
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error', 'Structure de workflow invalide');
    }

    #[Test]
    public function store_returns_409_when_file_already_exists(): void
    {
        $yaml = implode("\n", [
            'name: "Existing"',
            'project_path: "/tmp"',
            'agents:',
            '  - id: existing-agent',
            '    engine: claude-code',
            '    steps:',
            '      - "Do something"',
        ]);
        file_put_contents($this->tmpDir . '/existing.yaml', $yaml);

        $response = $this->postJson('/api/workflows', [
            'filename'     => 'existing',
            'yaml_content' => $yaml,
        ]);

        $response->assertStatus(409);
        $response->assertJsonPath('error', 'file_exists');
    }

    #[Test]
    public function store_overwrites_when_force_is_true(): void
    {
        $originalYaml = implode("\n", [
            'name: "Original"',
            'project_path: "/tmp"',
            'agents:',
            '  - id: original-agent',
            '    engine: claude-code',
            '    steps:',
            '      - "Original step"',
        ]);
        file_put_contents($this->tmpDir . '/overwrite-me.yaml', $originalYaml);

        $newYaml = implode("\n", [
            'name: "Updated"',
            'project_path: "/tmp/new"',
            'agents:',
            '  - id: updated-agent',
            '    engine: claude-code',
            '    steps:',
            '      - "New step"',
        ]);

        $response = $this->postJson('/api/workflows', [
            'filename'     => 'overwrite-me',
            'yaml_content' => $newYaml,
            'force'        => true,
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('name', 'Updated');
    }
}
