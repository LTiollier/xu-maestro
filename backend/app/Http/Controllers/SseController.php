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
            set_time_limit(0);
            $this->sseStreamService->setHeaders();

            // Détection retry : pull() consomme atomiquement le checkpoint (get + forget).
            // Si présent, le run reprend depuis le checkpoint sans passer par la garde done.
            $retryCheckpoint = cache()->pull("run:{$id}:retry_checkpoint");
            if ($retryCheckpoint) {
                cache()->forget("run:{$id}:event_log");
                try {
                    $this->runService->executeFromCheckpoint($id, $retryCheckpoint);
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
                    // Garantit que done est posé même si executeFromCheckpoint lève avant son propre try/finally
                    cache()->put("run:{$id}:done", true, 3600);
                }
                return;
            }

            // Run actif — empêcher une double exécution concurrente
            if (cache()->has("run:{$id}")) {
                return;
            }

            // Reconnexion — run terminé : rejouer l'intégralité du log
            // Le frontend fermera l'EventSource sur run.completed/run.error
            if (cache()->has("run:{$id}:done")) {
                $log = cache()->get("run:{$id}:event_log", []);
                foreach ($log as $entry) {
                    echo "event: {$entry['type']}\n";
                    echo "data: " . json_encode($entry['payload'], JSON_THROW_ON_ERROR) . "\n\n";
                    flush();
                }
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
