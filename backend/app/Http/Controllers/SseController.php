<?php

declare(strict_types=1);

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
            set_time_limit(0);
            ignore_user_abort(true);

            // Libérer le lock de session pour permettre d'autres requêtes SSE ou AJAX (FR2.4)
            if (session()->isStarted()) {
                session()->writeClose();
            }

            $this->sseStreamService->setHeaders();

            $retryCheckpoint = cache()->pull("run:{$id}:retry_checkpoint");
            $isDone          = cache()->has("run:{$id}:done");
            $isActive        = cache()->has("run:{$id}");

            // Branch A: Reconnexion sur un run déjà terminé
            if ($isDone) {
                $this->replayEvents($id, 1, (int) cache()->get("run:{$id}:event_count", 0));
                flush();
                return;
            }

            // Branch B: Run déjà actif (reconnexion pendant exécution)
            // On replay le log existant puis on boucle pour attendre la suite
            if ($isActive) {
                $offset = (int) cache()->get("run:{$id}:event_count", 0);
                $this->replayEvents($id, 1, $offset);
                flush();

                while (! cache()->has("run:{$id}:done") && ! connection_aborted()) {
                    $count = (int) cache()->get("run:{$id}:event_count", 0);
                    if ($count > $offset) {
                        $this->replayEvents($id, $offset + 1, $count);
                        $offset = $count;
                        flush();
                    }
                    usleep(500000); // 500ms
                }

                // Flush final pour les events de complétion émis juste après la boucle
                $count = (int) cache()->get("run:{$id}:event_count", 0);
                $this->replayEvents($id, $offset + 1, $count);
                flush();
                return;
            }

            // Branch C: Premier démarrage du run (ou retry)
            try {
                if ($retryCheckpoint) {
                    $this->clearEventLog($id);
                    $this->runService->executeFromCheckpoint($id, $retryCheckpoint);
                } else {
                    $this->runService->execute($id, $config['workflowFile'], $config['brief']);
                }
            } catch (\Throwable $e) {
                if (! cache()->has("run:{$id}:error_emitted")) {
                    event(new RunError(
                        runId:          $id,
                        agentId:        'unknown',
                        step:           0,
                        message:        $e->getMessage(),
                        checkpointPath: '',
                    ));
                }
            } finally {
                cache()->put("run:{$id}:done", true, 3600);
            }
        }, 200, [
            'Content-Type'      => 'text/event-stream',
            'Cache-Control'     => 'no-cache',
            'X-Accel-Buffering' => 'no',
            'Connection'        => 'keep-alive',
        ]);
    }

    private function replayEvents(string $id, int $from, int $to): void
    {
        for ($i = $from; $i <= $to; $i++) {
            $entry = cache()->get("run:{$id}:event:{$i}");
            if ($entry !== null) {
                echo "event: {$entry['type']}\n";
                echo "data: " . json_encode($entry['payload'], JSON_THROW_ON_ERROR) . "\n\n";
            }
        }
    }

    private function clearEventLog(string $id): void
    {
        $count = (int) cache()->get("run:{$id}:event_count", 0);
        for ($i = 1; $i <= $count; $i++) {
            cache()->forget("run:{$id}:event:{$i}");
        }
        cache()->forget("run:{$id}:event_count");
    }
}
