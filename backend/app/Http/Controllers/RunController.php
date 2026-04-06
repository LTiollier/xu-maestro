<?php

namespace App\Http\Controllers;

use App\Http\Resources\RunResource;
use App\Services\RunService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RunController extends Controller
{
    public function __construct(private readonly RunService $runService) {}

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'workflowFile' => ['required', 'string', 'regex:/^[\w\-]+\.ya?ml$/'],
            'brief'        => ['required', 'string'],
        ]);

        $result = $this->runService->execute(
            $validated['workflowFile'],
            $validated['brief']
        );

        return (new RunResource($result))
            ->response()
            ->setStatusCode(201);
    }

    public function destroy(string $id): JsonResponse
    {
        if (! cache()->has("run:{$id}")) {
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
