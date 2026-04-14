<?php

namespace App\Drivers;

use App\Exceptions\CliExecutionException;
use Illuminate\Support\Facades\Process;

class ClaudeDriver implements DriverInterface
{
    public function execute(string $projectPath, string $systemPrompt, string $context, int $timeout, ?callable $onOutput = null): string
    {
        $command = 'claude -p --allowedTools "Bash,Read,Write,Edit" --output-format stream-json';

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

        $result = Process::path($projectPath)
            ->input($context)
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

        // Flush any remaining content in the buffer (final line without trailing newline)
        if ($buffer !== '') {
            $parseLine(trim($buffer));
        }

        if ($result->failed()) {
            throw new CliExecutionException('claude', $result->exitCode(), $result->errorOutput());
        }

        return $resultFound ? $finalResult : $result->output();
    }

    public function kill(int $pid): void
    {
        if ($pid > 0 && function_exists('posix_kill')) {
            posix_kill($pid, SIGTERM);
        }
    }
}
