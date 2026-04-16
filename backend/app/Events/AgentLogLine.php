<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

class AgentLogLine
{
    use Dispatchable;

    public function __construct(
        public readonly string $runId,
        public readonly string $agentId,
        public readonly string $line,
        public readonly int $step,
    ) {}
}
