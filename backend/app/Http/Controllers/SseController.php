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
                $log = cache()->get("run:{$id}:event_log", []);
                foreach ($log as $entry) {
                    echo "event: {$entry['type']}\n";
                    echo "data: " . json_encode($entry['payload'], JSON_THROW_ON_ERROR) . "\n\n";
                }
                flush();
                return;
            }

            // Branch B: Run déjà actif (reconnexion pendant exécution)
            // On replay le log existant puis on boucle pour attendre la suite
            if ($isActive) {
                $log = cache()->get("run:{$id}:event_log", []);
                $offset = count($log);
                foreach ($log as $entry) {
                    echo "event: {$entry['type']}\n";
                    echo "data: " . json_encode($entry['payload'], JSON_THROW_ON_ERROR) . "\n\n";
                }
                flush();

                while (! cache()->has("run:{$id}:done") && ! connection_aborted()) {
                    $log = cache()->get("run:{$id}:event_log", []);
                    $count = count($log);
                    if ($count > $offset) {
                        for ($i = $offset; $i < $count; $i++) {
                            $entry = $log[$i];
                            echo "event: {$entry['type']}\n";
                            echo "data: " . json_encode($entry['payload'], JSON_THROW_ON_ERROR) . "\n\n";
                            $offset++;
                        }
                        flush();
                    }
                    usleep(500000); // 500ms
                }
                
                // Flush final pour les events de complétion émis juste après la boucle
                $log = cache()->get("run:{$id}:event_log", []);
                $count = count($log);
                for ($i = $offset; $i < $count; $i++) {
                    $entry = $log[$i];
                    echo "event: {$entry['type']}\n";
                    echo "data: " . json_encode($entry['payload'], JSON_THROW_ON_ERROR) . "\n\n";
                }
                flush();
                return;
            }

            // Branch C: Premier démarrage du run (ou retry)
            try {
                if ($retryCheckpoint) {
                    cache()->forget("run:{$id}:event_log");
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
}
