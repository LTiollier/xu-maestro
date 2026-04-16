<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\CheckpointService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RunRetryStepTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'array']);
        $this->tmpDir = sys_get_temp_dir() . '/retry-step-test-' . uniqid();
        File::makeDirectory($this->tmpDir, 0755, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tmpDir)) {
            File::deleteDirectory($this->tmpDir);
        }
        parent::tearDown();
    }

    private function validCheckpoint(): array
    {
        return [
            'runId'           => 'test-run-id',
            'workflowFile'    => 'example.yaml',
            'brief'           => 'Mon brief',
            'completedAgents' => ['agent-one'],
            'currentAgent'    => 'agent-two',
            'currentStep'     => 1,
            'context'         => $this->tmpDir . '/session.md',
        ];
    }

    #[Test]
    public function it_returns_404_when_run_path_not_in_cache(): void
    {
        $runId = 'aaaabbbb-1111-2222-3333-444455556666';

        $response = $this->postJson("/api/runs/{$runId}/retry-step");

        $response->assertStatus(404)
            ->assertJsonPath('code', 'RUN_NOT_FOUND')
            ->assertJsonStructure(['message', 'code']);

        $this->assertStringContainsString($runId, $response->json('message'));
    }

    #[Test]
    public function it_returns_404_when_checkpoint_file_does_not_exist(): void
    {
        $runId = 'bbbbcccc-1111-2222-3333-444455556666';
        Cache::put("run:{$runId}:path", $this->tmpDir, 7200);
        // Pas de checkpoint.json dans tmpDir

        $response = $this->postJson("/api/runs/{$runId}/retry-step");

        $response->assertStatus(404)
            ->assertJsonPath('code', 'CHECKPOINT_NOT_FOUND');
    }

    #[Test]
    public function it_returns_202_and_stores_retry_checkpoint_when_valid(): void
    {
        $runId = 'ccccdddd-1111-2222-3333-444455556666';
        Cache::put("run:{$runId}:path", $this->tmpDir, 7200);

        // Écrire un checkpoint.json valide
        $checkpointService = new CheckpointService();
        $checkpointService->write($this->tmpDir, $this->validCheckpoint());

        $response = $this->postJson("/api/runs/{$runId}/retry-step");

        $response->assertStatus(202)
            ->assertJsonPath('runId', $runId)
            ->assertJsonPath('status', 'retrying');
    }

    #[Test]
    public function it_clears_done_flag_on_successful_retry_request(): void
    {
        $runId = 'ddddeee-1111-2222-3333-444455556666';
        Cache::put("run:{$runId}:path", $this->tmpDir, 7200);
        Cache::put("run:{$runId}:done", true, 3600);

        $checkpointService = new CheckpointService();
        $checkpointService->write($this->tmpDir, $this->validCheckpoint());

        $this->postJson("/api/runs/{$runId}/retry-step")->assertStatus(202);

        $this->assertFalse(Cache::has("run:{$runId}:done"), 'Le flag done doit être effacé');
    }

    #[Test]
    public function it_clears_error_emitted_flag_on_successful_retry_request(): void
    {
        $runId = 'eeeeffff-1111-2222-3333-444455556666';
        Cache::put("run:{$runId}:path", $this->tmpDir, 7200);
        Cache::put("run:{$runId}:error_emitted", true, 60);

        $checkpointService = new CheckpointService();
        $checkpointService->write($this->tmpDir, $this->validCheckpoint());

        $this->postJson("/api/runs/{$runId}/retry-step")->assertStatus(202);

        $this->assertFalse(Cache::has("run:{$runId}:error_emitted"), 'Le flag error_emitted doit être effacé');
    }

    #[Test]
    public function it_stores_retry_checkpoint_in_cache_on_success(): void
    {
        $runId = 'fffff000-1111-2222-3333-444455556666';
        Cache::put("run:{$runId}:path", $this->tmpDir, 7200);

        $checkpointData = $this->validCheckpoint();
        $checkpointService = new CheckpointService();
        $checkpointService->write($this->tmpDir, $checkpointData);

        $this->postJson("/api/runs/{$runId}/retry-step")->assertStatus(202);

        $stored = Cache::get("run:{$runId}:retry_checkpoint");
        $this->assertNotNull($stored, 'retry_checkpoint doit être stocké en cache');
        $this->assertSame('example.yaml', $stored['workflowFile']);
        $this->assertSame(1, $stored['currentStep']);
    }
}
