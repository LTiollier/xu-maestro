<?php

namespace App\Drivers;

use App\Exceptions\CliExecutionException;
use Illuminate\Support\Facades\Process;

class ClaudeDriver implements DriverInterface
{
    public function execute(string $projectPath, string $systemPrompt, string $context): string
    {
        $command = 'claude -p --output-format json --allowedTools "Bash,Read,Write,Edit"';

        if ($systemPrompt !== '') {
            $command .= ' --append-system-prompt ' . escapeshellarg($systemPrompt);
        }

        $result = Process::path($projectPath)
            ->input($context)
            ->timeout(config('xu-workflow.default_timeout', 120))
            ->run($command);

        if ($result->failed()) {
            throw new CliExecutionException('claude', $result->exitCode(), $result->errorOutput());
        }

        return $result->output();
    }
}
