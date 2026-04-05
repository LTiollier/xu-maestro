<?php

namespace App\Services;

use App\Drivers\DriverInterface;
use App\Exceptions\CliExecutionException;
use App\Exceptions\InvalidJsonOutputException;
use Illuminate\Support\Str;

class RunService
{
    public function __construct(
        private readonly DriverInterface $driver,
        private readonly YamlService $yamlService
    ) {}

    public function execute(string $workflowFile, string $brief): array
    {
        $workflow = $this->yamlService->load($workflowFile);

        $runId = Str::uuid()->toString();
        $createdAt = now()->toIso8601String();
        $startedAt = microtime(true);

        $context = json_encode(['brief' => $brief], JSON_THROW_ON_ERROR);
        $agentResults = [];

        foreach ($workflow['agents'] as $agent) {
            $agentId = $agent['id'];
            $systemPrompt = $this->resolveSystemPrompt($agent);

            try {
                $rawOutput = $this->driver->execute(
                    $workflow['project_path'],
                    $systemPrompt,
                    $context
                );
            } catch (CliExecutionException $e) {
                throw new CliExecutionException($agentId, $e->exitCode, $e->stderr);
            }

            $decoded = $this->validateJsonOutput($agentId, $rawOutput);

            $agentResults[] = [
                'id'     => $agentId,
                'status' => $decoded['status'],
            ];

            $context = json_encode($decoded, JSON_THROW_ON_ERROR);
        }

        $duration = (int) round((microtime(true) - $startedAt) * 1000);

        return [
            'runId'     => $runId,
            'status'    => 'completed',
            'agents'    => $agentResults,
            'duration'  => $duration,
            'createdAt' => $createdAt,
        ];
    }

    private function validateJsonOutput(string $agentId, string $rawOutput): array
    {
        $decoded = json_decode($rawOutput, true);

        if ($decoded === null || ! is_array($decoded)) {
            throw new InvalidJsonOutputException($agentId, $rawOutput, 'Not valid JSON object');
        }

        $required = ['step', 'status', 'output', 'next_action', 'errors'];
        foreach ($required as $field) {
            if (! array_key_exists($field, $decoded)) {
                throw new InvalidJsonOutputException($agentId, $rawOutput, "Missing field: {$field}");
            }
        }

        return $decoded;
    }

    private function resolveSystemPrompt(array $agent): string
    {
        if (isset($agent['system_prompt']) && $agent['system_prompt'] !== '') {
            return $agent['system_prompt'];
        }

        if (isset($agent['system_prompt_file']) && $agent['system_prompt_file'] !== '') {
            $path = config('xu-workflow.prompts_path') . '/' . basename($agent['system_prompt_file']);
            if (file_exists($path)) {
                $content = file_get_contents($path);
                if ($content !== false) {
                    return $content;
                }
            }
        }

        return '';
    }
}
