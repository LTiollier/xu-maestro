<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Resources\WorkflowResource;
use App\Services\YamlService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class WorkflowController extends Controller
{
    public function __construct(private readonly YamlService $yamlService) {}

    public function index(): AnonymousResourceCollection
    {
        $workflows = $this->yamlService->loadAll();

        return WorkflowResource::collection(collect($workflows));
    }
}
