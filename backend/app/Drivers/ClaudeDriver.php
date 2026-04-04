<?php

namespace App\Drivers;

class ClaudeDriver implements DriverInterface
{
    public function execute(string $prompt, array $options): string
    {
        // TODO Epic 2 - Story 2.1
        throw new \RuntimeException('Not implemented');
    }

    public function cancel(string $jobId): void
    {
        // TODO Epic 2 - Story 2.1
        throw new \RuntimeException('Not implemented');
    }
}
