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
}
