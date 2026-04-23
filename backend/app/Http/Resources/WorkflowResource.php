<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class WorkflowResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $mapAgent = fn ($agent) => array_filter([
            'id'            => $agent['id'],
            'engine'        => $agent['engine'],
            'timeout'       => (int) ($agent['timeout'] ?? config('xu-maestro.default_timeout')),
            'steps'         => $agent['steps'] ?? [],
            'mandatory'     => (bool) ($agent['mandatory'] ?? false),
            'max_retries'   => isset($agent['max_retries']) ? (int) $agent['max_retries'] : null,
            'skippable'     => (bool) ($agent['skippable'] ?? false),
            'interactive'   => (bool) ($agent['interactive'] ?? false),
            'system_prompt' => $agent['system_prompt'] ?? null,
            'loop'          => $agent['loop'] ?? null,
        ], fn ($v) => $v !== null);

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
