<?php

namespace App\Drivers;

interface DriverInterface
{
    /**
     * Execute a CLI agent sequentially.
     *
     * @param  string        $projectPath   Working directory for the CLI process (from YAML project_path)
     * @param  string        $systemPrompt  System prompt to inject via --append-system-prompt (empty = omit flag)
     * @param  string        $context       JSON context passed via stdin (brief for first agent, output of previous for others)
     * @param  int           $timeout       Maximum execution time in seconds for this agent
     * @param  callable|null $onOutput      Optional callback invoked with each streamed text chunk during execution
     * @return string                       Raw stdout output from the CLI process
     */
    public function execute(string $projectPath, string $systemPrompt, string $context, int $timeout, ?callable $onOutput = null): string;

    /**
     * Send SIGTERM to a running CLI process by PID.
     * Used by SSE handler (Story 2.4) for mid-execution cancellation.
     */
    public function kill(int $pid): void;
}
