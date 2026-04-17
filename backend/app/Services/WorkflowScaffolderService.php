<?php

declare(strict_types=1);

namespace App\Services;

use App\Drivers\DriverResolver;
use App\Exceptions\ScaffoldException;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

class WorkflowScaffolderService
{

    public function __construct(
        private readonly DriverResolver $resolver,
        private readonly YamlService $yamlService,
    ) {}

    /**
     * @return array{yaml: string, parsed: array<string, mixed>}
     *
     * @throws ScaffoldException
     */
    public function scaffold(string $brief, string $engine = 'gemini-cli'): array
    {
        $driver  = $this->resolver->for($engine);
        $rawYaml = $driver->prompt($this->buildSystemPrompt(), $brief);
        $rawYaml = $this->stripFences($rawYaml);

        try {
            $parsed = Yaml::parse($rawYaml);
        } catch (ParseException $e) {
            throw new ScaffoldException('YAML invalide : ' . $e->getMessage(), $rawYaml);
        }

        // Auto-fix missing project_path
        if (is_array($parsed) && ! isset($parsed['project_path'])) {
            $parsed['project_path'] = '.';
        }

        if (! $this->yamlService->validate($parsed)) {
            throw new ScaffoldException('Structure de workflow invalide', $rawYaml);
        }

        return ['yaml' => $rawYaml, 'parsed' => $parsed];
    }

    private function buildSystemPrompt(): string
    {
        $docPath       = base_path('../docs/workflow-yaml-configuration.md');
        $documentation = is_readable($docPath) ? (string) file_get_contents($docPath) : '';

        return <<<PROMPT
You are a YAML workflow generator for the "xu-workflow" system.
Respond with ONLY a valid YAML block. No explanation, no prose, no markdown fences.

### CRITICAL RULES:
1. The root MUST have: `name`, `project_path` (string), and `agents` (array).
2. DO NOT use `phases` or `tasks`. Use `agents` instead.
3. Each agent MUST have `id`, `engine` (gemini-cli or claude-code), and `steps` (array of strings).
4. `project_path` should be "." if not specified.

### EXAMPLE OF VALID STRUCTURE:
```yaml
name: "My Workflow"
project_path: "."
agents:
  - id: research-agent
    engine: gemini-cli
    steps:
      - "task 1"
      - "task 2"
  - id: dev-agent
    engine: claude-code
    steps:
      - "task 3"
```

### DOCUMENTATION:
{$documentation}

### GOAL:
Generate a valid YAML workflow for the following request:
PROMPT;
    }

    private function stripFences(string $yaml): string
    {
        return (string) preg_replace('/^```(?:yaml)?\s*\n?(.*?)\n?```\s*$/s', '$1', trim($yaml));
    }
}
