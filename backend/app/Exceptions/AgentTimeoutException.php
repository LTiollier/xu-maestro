<?php

namespace App\Exceptions;

class AgentTimeoutException extends \RuntimeException
{
    public function __construct(
        public readonly string $agentId,
        public readonly int $timeout
    ) {
        parent::__construct("Agent '{$agentId}' timed out after {$timeout} seconds");
    }
}
