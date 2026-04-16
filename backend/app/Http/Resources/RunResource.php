<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class RunResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'runId'     => $this->resource['runId'],
            'status'    => $this->resource['status'],
            'agents'    => $this->resource['agents'],
            'duration'  => $this->resource['duration'],
            'createdAt' => $this->resource['createdAt'],
            'runFolder' => $this->resource['runFolder'],
        ];
    }
}
