<?php

declare(strict_types=1);

namespace App\Drivers;

use App\Exceptions\CliExecutionException;
use Illuminate\Support\Facades\Process;

final class GeminiDriver implements DriverInterface
{
    public function execute(string $projectPath, string $systemPrompt, string $context, int $timeout, ?callable $onOutput = null): string
    {
        [, $getResult] = $this->startAsync($projectPath, $systemPrompt, $context, $timeout, $onOutput);

        return $getResult();
    }

    public function startAsync(string $projectPath, string $systemPrompt, string $context, int $timeout, ?callable $onOutput = null): array
    {
        $command = 'gemini --prompt "" --output-format stream-json';

        if (config('xu-maestro.yolo_mode')) {
            $command .= ' --yolo';
        }

        $input = $systemPrompt !== ''
            ? $systemPrompt . "\n\n" . $context
            : $context;

        $buffer              = '';
        $accumulatedResponse = '';

        $parseLine = function (string $line) use (&$accumulatedResponse, $onOutput): void {
            $data = json_decode($line, true);

            if (! is_array($data)) {
                return;
            }

            $type = $data['type'] ?? '';

            if ($type === 'message') {
                $role    = $data['role'] ?? '';
                $content = $data['content'] ?? '';

                // Ignore the first user message as it is the prompt we provided via stdin
                if ($role === 'user') {
                    return;
                }

                if ($role === 'assistant') {
                    if ($onOutput !== null && $content !== '') {
                        try {
                            $onOutput($content);
                        } catch (\Throwable) {
                            // SSE pipe broken
                        }
                    }
                    $accumulatedResponse .= $content;
                }
            }

            if ($type === 'tool_use' && $onOutput !== null) {
                try {
                    $toolName = $data['tool_name'] ?? 'tool';
                    $onOutput("Executing {$toolName}...\n");
                } catch (\Throwable) {
                }
            }
        };

        $process = Process::path($projectPath)
            ->input($input)
            ->timeout($timeout)
            ->start($command, function (string $type, string $chunk) use (&$buffer, $parseLine): void {
                if ($type !== 'out') {
                    return;
                }

                $buffer .= $chunk;

                while (($pos = strpos($buffer, "\n")) !== false) {
                    $line   = trim(substr($buffer, 0, $pos));
                    $buffer = substr($buffer, $pos + 1);

                    if ($line !== '') {
                        $parseLine($line);
                    }
                }
            });

        $getResult = function () use ($process, &$buffer, &$accumulatedResponse, $parseLine): string {
            try {
                $result = $process->wait();

                // Flush any remaining content in the buffer
                if ($buffer !== '') {
                    $parseLine(trim($buffer));
                }

                if ($result->failed()) {
                    throw new CliExecutionException('gemini', $result->exitCode(), $result->errorOutput());
                }

                return $accumulatedResponse ?: $result->output();
            } catch (\LogicException) {
                // Process was already detected as terminated by a concurrent running() poll
                // (parallel execution). All pipe output was already flushed via readPipes().
                if ($buffer !== '') {
                    $parseLine(trim($buffer));
                    $buffer = '';
                }

                if (! $accumulatedResponse) {
                    throw new CliExecutionException('gemini', 1, 'Process terminated without producing a result');
                }

                return $accumulatedResponse;
            }
        };

        return [$process, $getResult];
    }

    public function prompt(string $systemPrompt, string $userPrompt, int $timeout = 60): string
    {
        return $this->execute(sys_get_temp_dir(), $systemPrompt, $userPrompt, $timeout);
    }

    public function kill(int $pid): void
    {
        if ($pid > 0 && function_exists('posix_kill')) {
            posix_kill($pid, SIGTERM);
        }
    }
}
