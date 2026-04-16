<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Resources\RunHistoryResource;
use App\Services\CheckpointService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class RunController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        $runsPath = config('xu-workflow.runs_path');

        if (! File::exists($runsPath)) {
            return RunHistoryResource::collection([]);
        }

        $runs = [];

        foreach (File::directories($runsPath) as $dir) {
            $checkpointPath = $dir . '/checkpoint.json';
            $resultPath     = $dir . '/result.json';

            if (! File::exists($checkpointPath) || ! File::exists($resultPath)) {
                continue;
            }

            try {
                $checkpoint = json_decode(File::get($checkpointPath), true, 512, JSON_THROW_ON_ERROR);
                $result     = json_decode(File::get($resultPath), true, 512, JSON_THROW_ON_ERROR);
            } catch (\Throwable) {
                continue;
            }

            $runId = $checkpoint['runId'] ?? null;
            if (! $runId) {
                continue;
            }

            // Mettre en cache le chemin pour les futurs appels à findRunPath (Story 4.1 optimization)
            cache()->put("run_path:{$runId}", $dir, 86400);

            // Exclure les runs encore actifs en cache (AC 7)
            if (cache()->has("run:{$runId}")) {
                continue;
            }

            $status = $result['status'] ?? null;
            if (! in_array($status, ['completed', 'error', 'cancelled'], true)) {
                continue;
            }

            // Parser createdAt depuis le nom du dossier (format Y-m-d-His, ex: 2026-04-11-143022)
            $folderName = basename($dir);
            try {
                $createdAt = Carbon::createFromFormat('Y-m-d-His', $folderName, 'UTC')->toIso8601String();
            } catch (\Exception) {
                $createdAt = Carbon::createFromTimestamp(File::lastModified($dir))->toIso8601String();
            }

            $runs[] = [
                'runId'           => $runId,
                'workflowFile'    => $checkpoint['workflowFile'] ?? '',
                'status'          => $status,
                'duration'        => $result['duration'] ?? null,
                'agentCount'      => $result['agentCount'] ?? 0,
                'runFolder'       => $dir,
                'createdAt'       => $createdAt,
                'completedAgents' => $checkpoint['completedAgents'] ?? [],
                'currentAgent'    => $checkpoint['currentAgent'] ?? null,
            ];
        }

        // Tri par date décroissante (le plus récent en premier)
        usort($runs, fn ($a, $b) => strcmp($b['createdAt'], $a['createdAt']));

        return RunHistoryResource::collection($runs);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'workflowFile' => ['required', 'string', 'regex:/^[\w\-]+\.ya?ml$/'],
            'brief'        => ['required', 'string'],
        ]);

        $runId = Str::uuid()->toString();

        cache()->put("run:{$runId}:config", [
            'workflowFile' => $validated['workflowFile'],
            'brief'        => $validated['brief'],
        ], 3600);

        return response()->json([
            'runId'  => $runId,
            'status' => 'pending',
        ], 202);
    }

    public function destroy(string $id): JsonResponse
    {
        if (! cache()->has("run:{$id}") && ! cache()->has("run:{$id}:config")) {
            return response()->json([
                'message' => "Run not found or already completed: {$id}",
                'code'    => 'RUN_NOT_FOUND',
            ], 404);
        }

        cache()->put("run:{$id}:cancelled", true, 3600);

        return response()->json([
            'message' => 'Cancellation requested',
            'runId'   => $id,
        ], 202);
    }

    public function retryStep(string $id, CheckpointService $checkpointService): JsonResponse
    {
        $runPath = cache()->get("run:{$id}:path");

        if (! $runPath) {
            return response()->json([
                'message' => "Run not found or path unavailable: {$id}",
                'code'    => 'RUN_NOT_FOUND',
            ], 404);
        }

        try {
            $checkpoint = $checkpointService->read($runPath);
        } catch (\RuntimeException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'code'    => 'CHECKPOINT_NOT_FOUND',
            ], 404);
        }

        // Réinitialiser les drapeaux d'état
        cache()->forget("run:{$id}:done");
        cache()->forget("run:{$id}:error_emitted");
        cache()->forget("run:{$id}:cancelled");

        // Effacer la réponse utilisateur en attente sur l'agent fautif
        // pour éviter qu'une ancienne réponse soit réutilisée sans re-prompt
        $currentAgentId = $checkpoint['currentAgent'] ?? null;
        if ($currentAgentId) {
            cache()->forget("run:{$id}:user_answer:{$currentAgentId}");
            cache()->forget("run:{$id}:pending_question:{$currentAgentId}");
        }

        // Stocker le checkpoint pour le stream SSE
        cache()->put("run:{$id}:retry_checkpoint", $checkpoint, 3600);

        return response()->json([
            'runId'  => $id,
            'status' => 'retrying',
        ], 202);
    }

    public function answer(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'agentId' => ['required', 'string'],
            'answer'  => ['required', 'string', 'min:1'],
        ]);

        if (! cache()->has("run:{$id}") && ! cache()->has("run:{$id}:path")) {
            return response()->json([
                'message' => "Run not found or already completed: {$id}",
                'code'    => 'RUN_NOT_FOUND',
            ], 404);
        }

        $cacheKey = "run:{$id}:user_answer:{$validated['agentId']}";

        // Idempotent : si une réponse est déjà enregistrée, ne pas l'écraser
        if (cache()->has($cacheKey)) {
            return response()->json([
                'runId'   => $id,
                'agentId' => $validated['agentId'],
                'status'  => 'already_answered',
            ], 409);
        }

        cache()->put($cacheKey, $validated['answer'], 3600);

        return response()->json([
            'runId'   => $id,
            'agentId' => $validated['agentId'],
            'status'  => 'accepted',
        ], 202);
    }

    public function logs(string $id): JsonResponse
    {
        // Try cache first (active run)
        $runPath = cache()->get("run:{$id}:path");

        // If not in cache, try historical path cache
        if (! $runPath) {
            $runPath = cache()->get("run_path:{$id}");
        }

        // If still not found, scan directories (history run)
        if (! $runPath) {
            $runPath = $this->findRunPath($id);
        }

        if (! $runPath || ! File::exists($runPath . '/session.md')) {
            return response()->json([
                'message' => "Logs not found for run: {$id}",
                'code'    => 'LOGS_NOT_FOUND',
            ], 404);
        }

        return response()->json([
            'runId' => $id,
            'logs'  => File::get($runPath . '/session.md'),
        ]);
    }

    private function findRunPath(string $runId): ?string
    {
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
                if (($checkpoint['runId'] ?? null) === $runId) {
                    // Mettre en cache pour la prochaine fois
                    cache()->put("run_path:{$runId}", $dir, 86400);
                    return $dir;
                }
            } catch (\Throwable) {
                continue;
            }
        }

        return null;
    }
}
