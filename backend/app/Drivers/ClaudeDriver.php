<?php

namespace App\Drivers;

use App\Exceptions\CliExecutionException;
use Illuminate\Support\Facades\Process;

class ClaudeDriver implements DriverInterface
{
    public function execute(string $projectPath, string $systemPrompt, string $context, int $timeout): string
    {
        $command = 'claude -p --allowedTools "Bash,Read,Write,Edit"';

        if ($systemPrompt !== '') {
            $command .= ' --append-system-prompt ' . escapeshellarg($systemPrompt);
        }

        $result = Process::path($projectPath)
            ->input($context)
            ->timeout($timeout)
            ->run($command);

        if ($result->failed()) {
            throw new CliExecutionException('claude', $result->exitCode(), $result->errorOutput());
        }

        return $result->output();
    }

    public function kill(int $pid): void
    {
        if ($pid > 0 && function_exists('posix_kill')) {
            posix_kill($pid, SIGTERM);
        }
    }
}
