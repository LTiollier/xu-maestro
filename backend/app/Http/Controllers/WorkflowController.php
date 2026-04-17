<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exceptions\ScaffoldException;
use App\Http\Requests\GenerateWorkflowRequest;
use App\Http\Requests\StoreWorkflowRequest;
use App\Http\Resources\WorkflowResource;
use App\Services\WorkflowScaffolderService;
use App\Services\YamlService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

final class WorkflowController extends Controller
{
    public function __construct(
        private readonly YamlService $yamlService,
        private readonly WorkflowScaffolderService $scaffolder,
    ) {}

    public function index(): AnonymousResourceCollection
    {
        $workflows = $this->yamlService->loadAll();

        return WorkflowResource::collection(collect($workflows));
    }

    public function generate(GenerateWorkflowRequest $request): JsonResponse
    {
        try {
            $result = $this->scaffolder->scaffold(
                $request->validated('brief'),
                $request->validated('engine', 'gemini-cli')
            );
        } catch (ScaffoldException $e) {
            return response()->json([
                'error'    => $e->getMessage(),
                'raw_yaml' => $e->rawYaml,
            ], 422);
        }

        return response()->json($result);
    }

    public function store(StoreWorkflowRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $force     = (bool) ($validated['force'] ?? false);

        // Step 1: Parse YAML
        try {
            $parsed = Yaml::parse($validated['yaml_content']);
        } catch (ParseException $e) {
            return response()->json(['error' => 'YAML invalide : ' . $e->getMessage()], 422);
        }

        // Step 2: Validate structure
        if (! $this->yamlService->validate($parsed)) {
            return response()->json(['error' => 'Structure de workflow invalide'], 422);
        }

        // Step 3: Enforce non-empty project_path (prevent saving unrunnable workflow)
        if (! isset($parsed['project_path']) || trim((string) $parsed['project_path']) === '') {
            return response()->json(['error' => 'Le champ project_path est requis avant de sauvegarder'], 422);
        }

        // Step 4: Save
        try {
            $this->yamlService->save($validated['filename'], $validated['yaml_content'], $force);
        } catch (\RuntimeException $e) {
            if (str_contains($e->getMessage(), 'already exists')) {
                return response()->json(['error' => 'file_exists'], 409);
            }

            return response()->json(['error' => $e->getMessage()], 500);
        }

        // Step 5: Load and return
        try {
            $workflow = $this->yamlService->load($validated['filename'] . '.yaml');
        } catch (\Throwable $e) {
            return response()->json(['error' => 'Workflow saved but could not be loaded: ' . $e->getMessage()], 500);
        }

        return response()->json((new WorkflowResource($workflow))->toArray($request), 201);
    }
}
