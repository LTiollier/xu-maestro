<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Events\RunError;
use App\Services\RunService;
use App\Services\SseStreamService;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Support\Facades\File;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EventsSseController extends Controller
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

            if (session()->isStarted()) {
                session()->writeClose();
            }

            $this->sseStreamService->setHeaders();

            // Active le mode unifié : SseEmitter intercalera les log.append après chaque événement
            cache()->put("run:{$id}:unified", true, 7200);

            $retryCheckpoint = cache()->pull("run:{$id}:retry_checkpoint");
            $isDone          = cache()->has("run:{$id}:done");
            $isActive        = cache()->has("run:{$id}");

            // Branch A: Run déjà terminé — replay complet puis fermeture
            if ($isDone) {
                $this->replayEvents($id, 1, (int) cache()->get("run:{$id}:event_count", 0));
                $runPath = cache()->get("run:{$id}:path");
                if ($runPath !== null) {
                    $this->emitNewLogContent($runPath, 0);
                }
                echo "event: log.done\ndata: {}\n\n";
                flush();
                return;
            }

            // Branch B: Run actif (reconnexion pendant exécution) — replay puis polling
            if ($isActive) {
                $eventOffset = (int) cache()->get("run:{$id}:event_count", 0);
                $logOffset   = 0;
                $runPath     = cache()->get("run:{$id}:path");

                $this->replayEvents($id, 1, $eventOffset);
                if ($runPath !== null) {
                    $logOffset = $this->emitNewLogContent($runPath, $logOffset);
                }
                flush();

                while (! cache()->has("run:{$id}:done") && ! connection_aborted()) {
                    if ($runPath === null) {
                        $runPath = cache()->get("run:{$id}:path");
                    }

                    $count = (int) cache()->get("run:{$id}:event_count", 0);
                    if ($count > $eventOffset) {
                        $this->replayEvents($id, $eventOffset + 1, $count);
                        $eventOffset = $count;
                    }

                    if ($runPath !== null) {
                        $logOffset = $this->emitNewLogContent($runPath, $logOffset);
                    }

                    flush();
                    usleep(500_000);
                }

                // Lecture finale après le flag done
                $count = (int) cache()->get("run:{$id}:event_count", 0);
                $this->replayEvents($id, $eventOffset + 1, $count);
                if ($runPath !== null) {
                    $this->emitNewLogContent($runPath, $logOffset);
                }
                echo "event: log.done\ndata: {}\n\n";
                flush();
                return;
            }

            // Branch C: Premier démarrage (ou retry) — exécution synchrone
            // SseEmitter intercale log.append après chaque événement via flushNewLogContent()
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

            // Flush final : octets écrits après le dernier événement + signal de fin
            $runPath   = cache()->get("run:{$id}:path");
            $logOffset = (int) cache()->get("run:{$id}:log_offset", 0);
            if ($runPath !== null) {
                $this->emitNewLogContent($runPath, $logOffset);
            }
            echo "event: log.done\ndata: {}\n\n";
            flush();
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

    /**
     * Émet les nouveaux octets de session.md depuis $offset et retourne le nouvel offset.
     */
    private function emitNewLogContent(string $runPath, int $offset): int
    {
        $sessionPath = $runPath . '/session.md';
        if (! File::exists($sessionPath)) {
            return $offset;
        }

        try {
            $content = File::get($sessionPath);
            $chunk   = substr($content, $offset);
            if ($chunk !== '') {
                echo "event: log.append\n";
                echo 'data: ' . json_encode(['chunk' => $chunk], JSON_THROW_ON_ERROR) . "\n\n";
                return $offset + strlen($chunk);
            }
        } catch (FileNotFoundException|\JsonException) {
            // Fichier supprimé ou chunk non-UTF-8 — offset inchangé
        }

        return $offset;
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
