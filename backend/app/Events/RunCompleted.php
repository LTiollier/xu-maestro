<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

class RunCompleted
{
    use Dispatchable;

    public function __construct(
        public readonly string $runId,
        public readonly int $duration,
        public readonly int $agentCount,
        public readonly string $status,
        public readonly string $runFolder,
    ) {}
}
