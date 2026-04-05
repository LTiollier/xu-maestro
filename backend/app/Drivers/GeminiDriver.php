<?php

namespace App\Drivers;

use App\Exceptions\CliExecutionException;
use Illuminate\Support\Facades\Process;

class GeminiDriver implements DriverInterface
{
    public function execute(string $projectPath, string $systemPrompt, string $context): string
    {
        $command = 'gemini -p --yolo';

        if ($systemPrompt !== '') {
            $command .= ' --append-system-prompt ' . escapeshellarg($systemPrompt);
        }

        $result = Process::path($projectPath)
            ->input($context)
            ->timeout(config('xu-workflow.default_timeout', 120))
            ->run($command);

        if ($result->failed()) {
            throw new CliExecutionException('gemini', $result->exitCode(), $result->errorOutput());
        }

        return $result->output();
    }
}
