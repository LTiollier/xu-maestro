<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class RunController extends Controller
{
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
}
