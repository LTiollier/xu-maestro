<?php

namespace App\Drivers;

use App\Exceptions\CliExecutionException;
use Illuminate\Support\Facades\Process;

class GeminiDriver implements DriverInterface
{
    public function execute(string $projectPath, string $systemPrompt, string $context, int $timeout, ?callable $onOutput = null): string
    {
        $command = 'gemini --prompt "" --yolo';

        $input = $systemPrompt !== ''
            ? $systemPrompt . "\n\n" . $context
            : $context;

        $result = Process::path($projectPath)
            ->input($input)
            ->timeout($timeout)
            ->run($command);

        if ($result->failed()) {
            throw new CliExecutionException('gemini', $result->exitCode(), $result->errorOutput());
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
