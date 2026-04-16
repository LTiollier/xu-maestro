<?php

declare(strict_types=1);

namespace App\Exceptions;

class CliExecutionException extends \RuntimeException
{
    public function __construct(
        public readonly string $agentId,
        public readonly int $exitCode,
        public readonly string $stderr
    ) {
        parent::__construct("CLI execution failed for agent '{$agentId}' (exit code {$exitCode}): {$stderr}");
    }
}
