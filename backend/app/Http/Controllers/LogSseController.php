<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\SseStreamService;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Support\Facades\File;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LogSseController extends Controller
{
    public function __construct(
        private readonly SseStreamService $sseStreamService,
    ) {}

    public function stream(string $id): StreamedResponse
    {
        // On vérifie si le run est connu (soit déjà lancé, soit en cours d'initialisation)
        $isKnown = cache()->has("run:{$id}:config") || cache()->has("run:{$id}:path") || cache()->has("run:{$id}");

        if (! $isKnown) {
            abort(404, "Run not found: {$id}");
        }

        return new StreamedResponse(function () use ($id) {
            set_time_limit(0);
            ignore_user_abort(true);

            if (session()->isStarted()) {
                session()->writeClose();
            }

            $this->sseStreamService->setHeaders();

            $runPath     = null;
            $sessionPath = null;
            $offset      = 0;

            while (true) {
                // Arrêter si le client a fermé la connexion
                if (connection_aborted()) {
                    break;
                }

                // Tenter de résoudre le chemin si on ne l'a pas encore
                if (! $runPath) {
                    $runPath = $this->resolveRunPath($id);
                    if ($runPath) {
                        $sessionPath = $runPath . '/session.md';
                    }
                }

                if ($runPath && $sessionPath && File::exists($sessionPath)) {
                    try {
                        $content = File::get($sessionPath);
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
                    } catch (FileNotFoundException) {
                        // Fichier supprimé entre le exists() et le get() — on ignore ce tick
                    }
                }

                if (cache()->has("run:{$id}:done")) {
                    // Lecture finale pour capturer les bytes écrits juste avant le flag done
                    if ($sessionPath && File::exists($sessionPath)) {
                        try {
                            $final = File::get($sessionPath);
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
                        } catch (FileNotFoundException) {
                            // Fichier supprimé entre le exists() et le get() — on ignore
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
        return cache()->get("run:{$id}:path") ?: null;
    }
}
