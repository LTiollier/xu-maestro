<?php

namespace App\Drivers;

interface DriverInterface
{
    /**
     * Execute a CLI agent sequentially.
     *
     * @param  string  $projectPath   Working directory for the CLI process (from YAML project_path)
     * @param  string  $systemPrompt  System prompt to inject via --append-system-prompt (empty = omit flag)
     * @param  string  $context       JSON context passed via stdin (brief for first agent, output of previous for others)
     * @return string                 Raw stdout output from the CLI process
     */
    public function execute(string $projectPath, string $systemPrompt, string $context): string;
}
