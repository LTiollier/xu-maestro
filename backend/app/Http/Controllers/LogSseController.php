<?php

namespace App\Http\Controllers;

use App\Services\SseStreamService;
use Illuminate\Support\Facades\File;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LogSseController extends Controller
{
    public function __construct(
        private readonly SseStreamService $sseStreamService,
    ) {}

    public function stream(string $id): StreamedResponse
    {
        $runPath = $this->resolveRunPath($id);

        if (! $runPath) {
            abort(404, "Run not found: {$id}");
        }

        return new StreamedResponse(function () use ($id, $runPath) {
            set_time_limit(0);
            ignore_user_abort(true);
            $this->sseStreamService->setHeaders();

            $sessionPath = $runPath . '/session.md';
            $offset      = 0;

            while (true) {
                // P2: arrêter si le client a fermé la connexion
                if (connection_aborted()) {
                    break;
                }

                if (File::exists($sessionPath)) {
                    $content = file_get_contents($sessionPath);
                    if ($content !== false) {
                        $chunk = substr($content, $offset);
                        if (strlen($chunk) > 0) {
                            $offset += strlen($chunk);
                            try {
                                // P6: json_encode peut échouer sur UTF-8 invalide
                                echo "event: log.append\n";
                                echo 'data: ' . json_encode(['chunk' => $chunk], JSON_THROW_ON_ERROR) . "\n\n";
                                flush();
                            } catch (\JsonException) {
                                // Chunk non-UTF-8 ignoré silencieusement
                            }
                        }
                    }
                }

                if (cache()->has("run:{$id}:done")) {
                    // P1: lecture finale pour capturer les bytes écrits juste avant le flag done
                    if (File::exists($sessionPath)) {
                        $final = file_get_contents($sessionPath);
                        if ($final !== false) {
                            $tail = substr($final, $offset);
                            if (strlen($tail) > 0) {
                                try {
                                    echo "event: log.append\n";
                                    echo 'data: ' . json_encode(['chunk' => $tail], JSON_THROW_ON_ERROR) . "\n\n";
                                    flush();
                                } catch (\JsonException) {
                                    // Chunk non-UTF-8 ignoré
                                }
                            }
                        }
                    }
                    echo "event: log.done\ndata: {}\n\n";
                    flush();
                    break;
                }

                usleep(500_000);
            }
        }, 200, [
            'Content-Type'      => 'text/event-stream',
            'Cache-Control'     => 'no-cache',
            'X-Accel-Buffering' => 'no',
            'Connection'        => 'keep-alive',
        ]);
    }

    private function resolveRunPath(string $id): ?string
    {
        $runPath = cache()->get("run:{$id}:path");

        if ($runPath) {
            return $runPath;
        }

        $runsPath = config('xu-workflow.runs_path');
        if (! File::exists($runsPath)) {
            return null;
        }

        foreach (File::directories($runsPath) as $dir) {
            $checkpointPath = $dir . '/checkpoint.json';
            if (! File::exists($checkpointPath)) {
                continue;
            }
            try {
                $checkpoint = json_decode(File::get($checkpointPath), true, 512, JSON_THROW_ON_ERROR);
                if (($checkpoint['runId'] ?? null) === $id) {
                    return $dir;
                }
            } catch (\Throwable) {
                continue;
            }
        }

        return null;
    }
}
