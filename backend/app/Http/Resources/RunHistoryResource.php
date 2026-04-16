<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class RunHistoryResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'runId'           => $this->resource['runId'],
            'workflowFile'    => $this->resource['workflowFile'],
            'status'          => $this->resource['status'],
            'duration'        => $this->resource['duration'],
            'agentCount'      => $this->resource['agentCount'],
            'runFolder'       => $this->resource['runFolder'],
            'createdAt'       => $this->resource['createdAt'],
            'completedAgents' => $this->resource['completedAgents'] ?? [],
            'currentAgent'    => $this->resource['currentAgent'] ?? null,
        ];
    }
}
