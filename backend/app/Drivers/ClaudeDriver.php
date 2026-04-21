<?php

declare(strict_types=1);

namespace App\Drivers;

use App\Exceptions\CliExecutionException;
use Illuminate\Support\Facades\Process;

final class ClaudeDriver implements DriverInterface
{
    public function execute(string $projectPath, string $systemPrompt, string $context, int $timeout, ?callable $onOutput = null): string
    {
        [, $getResult] = $this->startAsync($projectPath, $systemPrompt, $context, $timeout, $onOutput);

        return $getResult();
    }

    public function startAsync(string $projectPath, string $systemPrompt, string $context, int $timeout, ?callable $onOutput = null): array
    {
        $command = 'claude -p --verbose --allowedTools "Bash,Read,Write,Edit" --output-format stream-json';

        if (config('xu-maestro.yolo_mode')) {
            $command .= ' --dangerously-skip-permissions';
        }

        if ($systemPrompt !== '') {
            $command .= ' --append-system-prompt ' . escapeshellarg($systemPrompt);
        }

        $buffer      = '';
        $resultFound = false;
        $finalResult = '';

        $parseLine = function (string $line) use (&$resultFound, &$finalResult, $onOutput): void {
            $data = json_decode($line, true);

            if (! is_array($data)) {
                return;
            }

            if (($data['type'] ?? '') === 'assistant' && $onOutput !== null) {
                foreach ($data['message']['content'] ?? [] as $block) {
                    if (($block['type'] ?? '') === 'text') {
                        $text = trim($block['text'] ?? '');
                        if ($text !== '') {
                            try {
                                $onOutput($text);
                            } catch (\Throwable) {
                                // SSE pipe broken — continue processing the subprocess output
                            }
                        }
                    }
                }
            }

            if (($data['type'] ?? '') === 'result') {
                $resultFound = true;
                $finalResult = (string) ($data['result'] ?? '');
            }
        };

        $process = Process::path($projectPath)
            ->input($context)
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

        $getResult = function () use ($process, &$buffer, &$resultFound, &$finalResult, $parseLine): string {
            try {
                $result = $process->wait();

                // Flush any remaining content in the buffer (final line without trailing newline)
                if ($buffer !== '') {
                    $parseLine(trim($buffer));
                }

                if ($result->failed()) {
                    throw new CliExecutionException('claude', $result->exitCode(), $result->errorOutput());
                }

                return $resultFound ? $finalResult : $result->output();
            } catch (\LogicException) {
                // Process was already detected as terminated by a concurrent running() poll
                // (parallel execution). All pipe output was already flushed via readPipes().
                if ($buffer !== '') {
                    $parseLine(trim($buffer));
                    $buffer = '';
                }
                if (! $resultFound) {
                    throw new CliExecutionException('claude', 1, 'Process terminated without producing a result');
                }

                return $finalResult;
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
