<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

class AgentBubble
{
    use Dispatchable;

    public function __construct(
        public readonly string $runId,
        public readonly string $agentId,
        public readonly string $message,
        public readonly int $step,
        public readonly string $type = 'bubble',
    ) {}
}
