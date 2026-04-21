<?php

declare(strict_types=1);

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
     * Send a direct prompt to the model and return the response.
     * Useful for scaffolding or simple queries that don't require full agent execution.
     */
    public function prompt(string $systemPrompt, string $userPrompt, int $timeout = 60): string;

    /**
     * Start the agent process asynchronously without blocking.
     * Returns [InvokedProcess, callable(): string] — call the callable to wait and get the result.
     * Allows multiple agents to run concurrently (parallel group execution).
     *
     * @return array{0: \Illuminate\Process\InvokedProcess, 1: callable(): string}
     */
    public function startAsync(string $projectPath, string $systemPrompt, string $context, int $timeout, ?callable $onOutput = null): array;

    /**
     * Send SIGTERM to a running CLI process by PID.
     * Used by SSE handler (Story 2.4) for mid-execution cancellation.
     */
    public function kill(int $pid): void;
}
