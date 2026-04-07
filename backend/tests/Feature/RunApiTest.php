<?php

namespace Tests\Feature;

use App\Drivers\DriverInterface;
use App\Exceptions\InvalidJsonOutputException;
use App\Services\ArtifactService;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RunApiTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpDir = sys_get_temp_dir() . '/xu-workflow-run-test-' . uniqid();
        mkdir($this->tmpDir, 0755, true);

        Config::set('xu-workflow.workflows_path', $this->tmpDir);

        $mockArtifact = $this->createMock(ArtifactService::class);
        $mockArtifact->method('initializeRun')->willReturn('/tmp/test-run');
        $mockArtifact->method('getContextContent')->willReturn('# context');
        $this->app->instance(ArtifactService::class, $mockArtifact);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmpDir . '/*.yaml') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($this->tmpDir)) {
            rmdir($this->tmpDir);
        }

        parent::tearDown();
    }

    private function createYaml(string $filename, array $overrides = []): void
    {
        $yaml = array_merge([
            'name'         => 'Test Workflow',
            'project_path' => '/tmp/test-project',
            'agents'       => [
                [
                    'id'     => 'agent-one',
                    'engine' => 'claude-code',
                    'steps'  => ['Analyser le brief'],
                ],
            ],
        ], $overrides);

        $content = "name: \"{$yaml['name']}\"\n";
        $content .= "project_path: \"{$yaml['project_path']}\"\n";
        $content .= "agents:\n";
        foreach ($yaml['agents'] as $agent) {
            $content .= "  - id: {$agent['id']}\n";
            $content .= "    engine: {$agent['engine']}\n";
        }

        file_put_contents($this->tmpDir . '/' . $filename, $content);
    }

    private function validDriverOutput(): string
    {
        return json_encode([
            'step'        => 'analyse',
            'status'      => 'done',
            'output'      => 'Analyse terminée.',
            'next_action' => null,
            'errors'      => [],
        ]);
    }

    #[Test]
    public function it_returns_201_with_correct_structure_on_valid_run(): void
    {
        $this->createYaml('test-workflow.yaml');

        $mockDriver = $this->createMock(DriverInterface::class);
        $mockDriver->method('execute')->willReturn($this->validDriverOutput());
        $this->app->instance(DriverInterface::class, $mockDriver);

        $response = $this->postJson('/api/runs', [
            'workflowFile' => 'test-workflow.yaml',
            'brief'        => 'Ajouter des notifications in-app',
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure(['runId', 'status', 'agents', 'duration', 'createdAt', 'runFolder'])
            ->assertJsonPath('status', 'completed')
            ->assertJsonCount(1, 'agents')
            ->assertJsonPath('agents.0.id', 'agent-one');

        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/',
            $response->json('runId')
        );
    }

    #[Test]
    public function it_returns_422_when_workflow_file_not_found(): void
    {
        $response = $this->postJson('/api/runs', [
            'workflowFile' => 'inexistant.yaml',
            'brief'        => 'Test brief',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('code', 'YAML_INVALID');
    }

    #[Test]
    public function it_returns_422_when_driver_returns_invalid_json(): void
    {
        $this->createYaml('test-workflow.yaml');

        $mockDriver = $this->createMock(DriverInterface::class);
        $mockDriver->method('execute')->willReturn('not-valid-json');
        $this->app->instance(DriverInterface::class, $mockDriver);

        $response = $this->postJson('/api/runs', [
            'workflowFile' => 'test-workflow.yaml',
            'brief'        => 'Test brief',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('code', 'INVALID_JSON_OUTPUT');
    }

    #[Test]
    public function it_returns_422_when_request_missing_required_fields(): void
    {
        $response = $this->postJson('/api/runs', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['workflowFile', 'brief']);
    }

    #[Test]
    public function it_returns_no_data_wrapper(): void
    {
        $this->createYaml('test-workflow.yaml');

        $mockDriver = $this->createMock(DriverInterface::class);
        $mockDriver->method('execute')->willReturn($this->validDriverOutput());
        $this->app->instance(DriverInterface::class, $mockDriver);

        $response = $this->postJson('/api/runs', [
            'workflowFile' => 'test-workflow.yaml',
            'brief'        => 'Brief',
        ]);

        // withoutWrapping() doit être actif — pas de clé 'data'
        $response->assertJsonMissing(['data']);
        $response->assertJsonStructure(['runId', 'status', 'runFolder']);
    }
}
