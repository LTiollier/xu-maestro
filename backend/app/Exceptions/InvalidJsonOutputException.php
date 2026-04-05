<?php

namespace App\Exceptions;

class InvalidJsonOutputException extends \RuntimeException
{
    public function __construct(
        public readonly string $agentId,
        public readonly string $rawOutput,
        string $reason
    ) {
        parent::__construct("Agent '{$agentId}' returned invalid JSON: {$reason}");
    }
}
