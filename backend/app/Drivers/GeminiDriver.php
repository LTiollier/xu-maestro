<?php

declare(strict_types=1);

namespace App\Drivers;

use App\Exceptions\CliExecutionException;
use Illuminate\Support\Facades\Process;

class GeminiDriver implements DriverInterface
{
    public function execute(string $projectPath, string $systemPrompt, string $context, int $timeout, ?callable $onOutput = null): string
    {
        $command = 'gemini --prompt "" --output-format stream-json';

        if (config('xu-workflow.yolo_mode')) {
            $command .= ' --yolo';
        }

        $input = $systemPrompt !== ''
            ? $systemPrompt . "\n\n" . $context
            : $context;

        return $this->runGeminiStream($projectPath, $command, $input, $timeout, $onOutput);
    }

    public function prompt(string $systemPrompt, string $userPrompt, int $timeout = 60): string
    {
        $command = 'gemini --prompt "" --output-format stream-json';
        $input   = $systemPrompt . "\n\n" . $userPrompt;

        return $this->runGeminiStream(sys_get_temp_dir(), $command, $input, $timeout);
    }

    public function kill(int $pid): void
    {
        if ($pid > 0 && function_exists('posix_kill')) {
            posix_kill($pid, SIGTERM);
        }
    }

    private function runGeminiStream(string $path, string $command, string $input, int $timeout, ?callable $onOutput = null): string
    {
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

            // Log tool usage to provide real-time feedback
            if ($type === 'tool_use' && $onOutput !== null) {
                try {
                    $toolName = $data['tool_name'] ?? 'tool';
                    $onOutput("Executing {$toolName}...\n");
                } catch (\Throwable) {
                }
            }
        };

        $result = Process::path($path)
            ->input($input)
            ->timeout($timeout)
            ->run($command, function (string $type, string $chunk) use (&$buffer, $parseLine): void {
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

        // Flush any remaining content in the buffer
        if ($buffer !== '') {
            $parseLine(trim($buffer));
        }

        if ($result->failed()) {
            throw new CliExecutionException('gemini', $result->exitCode(), $result->errorOutput());
        }

        return $accumulatedResponse ?: $result->output();
    }
}
