<?php

namespace App\Http\Controllers;

use App\Events\RunError;
use App\Services\RunService;
use App\Services\SseStreamService;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SseController extends Controller
{
    public function __construct(
        private readonly RunService $runService,
        private readonly SseStreamService $sseStreamService,
    ) {}

    public function stream(string $id): StreamedResponse
    {
        $config = cache()->get("run:{$id}:config");

        if (! $config) {
            abort(404, "Run not found: {$id}");
        }

        return new StreamedResponse(function () use ($id, $config) {
            $this->sseStreamService->setHeaders();

            if (cache()->has("run:{$id}") || cache()->has("run:{$id}:done")) {
                return;
            }

            try {
                $this->runService->execute($id, $config['workflowFile'], $config['brief']);
            } catch (\Throwable $e) {
                // RunService émet RunError pour les erreurs d'agents connues (CliExecutionException,
                // ProcessTimedOutException, InvalidJsonOutputException) et pose le flag error_emitted.
                // Ce catch-all ne ré-émet que pour les erreurs inattendues non gérées dans RunService.
                if (! cache()->has("run:{$id}:error_emitted")) {
                    event(new RunError(
                        runId:          $id,
                        agentId:        'unknown',
                        step:           0,
                        message:        $e->getMessage(),
                        checkpointPath: '',
                    ));
                }
            }
        }, 200, [
            'Content-Type'      => 'text/event-stream',
            'Cache-Control'     => 'no-cache',
            'X-Accel-Buffering' => 'no',
            'Connection'        => 'keep-alive',
        ]);
    }
}
