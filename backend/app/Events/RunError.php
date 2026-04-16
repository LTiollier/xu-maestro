<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

class RunError
{
    use Dispatchable;

    public function __construct(
        public readonly string $runId,
        public readonly string $agentId,
        public readonly int $step,
        public readonly string $message,
        public readonly string $checkpointPath,
    ) {}
}
