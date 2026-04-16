<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

class AgentWaitingForInput
{
    use Dispatchable;

    public function __construct(
        public readonly string $runId,
        public readonly string $agentId,
        public readonly string $question,
        public readonly int $step,
    ) {}
}
