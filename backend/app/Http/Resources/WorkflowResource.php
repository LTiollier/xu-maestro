<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class WorkflowResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $mapAgent = fn ($agent) => [
            'id'      => $agent['id'],
            'engine'  => $agent['engine'],
            'timeout' => (int) ($agent['timeout'] ?? config('xu-maestro.default_timeout')),
            'steps'   => $agent['steps'] ?? [],
        ];

        return [
            'name'   => $this->resource['name'],
            'file'   => $this->resource['file'],
            'agents' => array_map(function ($step) use ($mapAgent) {
                if (array_key_exists('parallel', $step)) {
                    return ['parallel' => array_map($mapAgent, $step['parallel'])];
                }

                return $mapAgent($step);
            }, $this->resource['agents'] ?? []),
        ];
    }
}
