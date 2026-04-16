<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RunApiTest extends TestCase
{
    #[Test]
    public function it_returns_202_with_run_id_and_pending_status(): void
    {
        $response = $this->postJson('/api/runs', [
            'workflowFile' => 'test-workflow.yaml',
            'brief'        => 'Ajouter des notifications in-app',
        ]);

        $response->assertStatus(202)
            ->assertJsonStructure(['runId', 'status'])
            ->assertJsonPath('status', 'pending');

        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/',
            $response->json('runId')
        );
    }

    #[Test]
    public function it_stores_run_config_in_cache_after_post(): void
    {
        $response = $this->postJson('/api/runs', [
            'workflowFile' => 'my-workflow.yaml',
            'brief'        => 'Mon brief de test',
        ]);

        $response->assertStatus(202);

        $runId = $response->json('runId');
        $config = cache()->get("run:{$runId}:config");

        $this->assertNotNull($config);
        $this->assertSame('my-workflow.yaml', $config['workflowFile']);
        $this->assertSame('Mon brief de test', $config['brief']);
    }

    #[Test]
    public function it_returns_422_when_request_missing_required_fields(): void
    {
        $response = $this->postJson('/api/runs', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['workflowFile', 'brief']);
    }

    #[Test]
    public function it_returns_422_when_workflow_file_format_invalid(): void
    {
        $response = $this->postJson('/api/runs', [
            'workflowFile' => '../etc/passwd',
            'brief'        => 'Test brief',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['workflowFile']);
    }

    #[Test]
    public function it_accepts_yaml_extension_variants(): void
    {
        $responseYaml = $this->postJson('/api/runs', [
            'workflowFile' => 'my-flow.yaml',
            'brief'        => 'brief',
        ]);
        $responseYml = $this->postJson('/api/runs', [
            'workflowFile' => 'my-flow.yml',
            'brief'        => 'brief',
        ]);

        $responseYaml->assertStatus(202);
        $responseYml->assertStatus(202);
    }

    #[Test]
    public function it_generates_unique_run_ids_for_concurrent_posts(): void
    {
        $response1 = $this->postJson('/api/runs', [
            'workflowFile' => 'workflow.yaml',
            'brief'        => 'brief 1',
        ]);
        $response2 = $this->postJson('/api/runs', [
            'workflowFile' => 'workflow.yaml',
            'brief'        => 'brief 2',
        ]);

        $response1->assertStatus(202);
        $response2->assertStatus(202);

        $this->assertNotSame($response1->json('runId'), $response2->json('runId'));
    }
}
