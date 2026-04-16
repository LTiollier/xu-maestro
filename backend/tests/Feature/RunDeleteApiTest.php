<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RunDeleteApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'array']);
    }

    #[Test]
    public function it_returns_404_when_run_not_found(): void
    {
        $response = $this->deleteJson('/api/runs/nonexistent-run-id');

        $response->assertStatus(404)
            ->assertJsonPath('code', 'RUN_NOT_FOUND')
            ->assertJsonStructure(['message', 'code']);
    }

    #[Test]
    public function it_returns_202_and_sets_cancelled_flag_when_run_is_active(): void
    {
        $runId = 'aaaabbbb-cccc-dddd-eeee-ffffaaaabbbb';
        Cache::put("run:{$runId}", ['status' => 'running'], 3600);

        $response = $this->deleteJson("/api/runs/{$runId}");

        $response->assertStatus(202)
            ->assertJsonPath('runId', $runId)
            ->assertJsonStructure(['message', 'runId']);

        // Vérifier que le flag d'annulation est positionné dans le cache
        $this->assertTrue(Cache::get("run:{$runId}:cancelled", false));
    }

    #[Test]
    public function it_returns_404_after_run_completes_and_cache_expires(): void
    {
        $runId = 'bbbbcccc-dddd-eeee-ffff-aaaabbbbcccc';
        // Run non enregistré dans le cache (expiré ou jamais existé)

        $response = $this->deleteJson("/api/runs/{$runId}");

        $response->assertStatus(404)
            ->assertJsonPath('code', 'RUN_NOT_FOUND');
    }

    #[Test]
    public function it_returns_message_containing_run_id_on_404(): void
    {
        $runId = 'ccccdddd-eeee-ffff-aaaa-bbbbccccdddd';

        $response = $this->deleteJson("/api/runs/{$runId}");

        $response->assertStatus(404);
        $this->assertStringContainsString($runId, $response->json('message'));
    }
}
