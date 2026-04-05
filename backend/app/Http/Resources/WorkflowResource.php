<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class WorkflowResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'name'   => $this->resource['name'],
            'file'   => $this->resource['file'],
            'agents' => array_map(fn ($agent) => [
                'id'      => $agent['id'],
                'engine'  => $agent['engine'],
                'timeout' => (int) ($agent['timeout'] ?? config('xu-workflow.default_timeout')),
                'steps'   => $agent['steps'] ?? [],
            ], $this->resource['agents'] ?? []),
        ];
    }
}
