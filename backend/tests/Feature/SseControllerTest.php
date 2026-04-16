<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Events\RunError;
use App\Services\RunService;
use App\Services\SseStreamService;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SseControllerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // SseStreamService mock pour éviter ob_end_clean() dans le contexte de test
        $mockSseStream = $this->createMock(SseStreamService::class);
        $this->app->instance(SseStreamService::class, $mockSseStream);
    }

    #[Test]
    public function it_returns_404_when_run_config_not_in_cache(): void
    {
        $response = $this->get('/api/runs/nonexistent-run-id/stream');

        $response->assertStatus(404);
    }

    #[Test]
    public function it_returns_sse_headers_when_config_exists(): void
    {
        cache()->put('run:test-run-id:config', [
            'workflowFile' => 'test.yaml',
            'brief'        => 'test brief',
        ], 60);

        $mockRunService = $this->createMock(RunService::class);
        $this->app->instance(RunService::class, $mockRunService);

        $response = $this->get('/api/runs/test-run-id/stream');

        $this->assertStringContainsString('text/event-stream', $response->headers->get('Content-Type') ?? '');
        $this->assertStringContainsString('no-cache', $response->headers->get('Cache-Control') ?? '');
        $this->assertSame('no', $response->headers->get('X-Accel-Buffering'));
    }

    #[Test]
    public function it_calls_run_service_execute_with_correct_args(): void
    {
        cache()->put('run:my-run-id:config', [
            'workflowFile' => 'feature-dev.yaml',
            'brief'        => 'Mon brief SSE',
        ], 60);

        $mockRunService = $this->createMock(RunService::class);
        $mockRunService->expects($this->once())
            ->method('execute')
            ->with('my-run-id', 'feature-dev.yaml', 'Mon brief SSE');
        $this->app->instance(RunService::class, $mockRunService);

        // Invoquer manuellement le callback de la StreamedResponse
        $response = $this->get('/api/runs/my-run-id/stream');
        ob_start();
        $response->baseResponse->sendContent();
        ob_end_clean();
    }

    #[Test]
    public function it_emits_run_error_event_when_run_service_throws(): void
    {
        Event::fake();

        cache()->put('run:error-run-id:config', [
            'workflowFile' => 'test.yaml',
            'brief'        => 'brief',
        ], 60);

        $mockRunService = $this->createMock(RunService::class);
        $mockRunService->method('execute')
            ->willThrowException(new \RuntimeException('CLI process failed'));
        $this->app->instance(RunService::class, $mockRunService);

        $response = $this->get('/api/runs/error-run-id/stream');
        ob_start();
        $response->baseResponse->sendContent();
        ob_end_clean();

        Event::assertDispatched(RunError::class, function (RunError $e) {
            return $e->runId === 'error-run-id'
                && $e->message === 'CLI process failed';
        });
    }
}
