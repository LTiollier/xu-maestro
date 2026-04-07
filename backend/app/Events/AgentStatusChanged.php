<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

class AgentStatusChanged
{
    use Dispatchable;

    public function __construct(
        public readonly string $runId,
        public readonly string $agentId,
        public readonly string $status,
        public readonly int $step,
        public readonly string $message,
    ) {}
}
