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
    public function scaffold(string $brief, string $engine = 'gemini-cli', ?string $currentYaml = null): array
    {
        $driver  = $this->resolver->for($engine);
        $rawYaml = $driver->prompt($this->buildSystemPrompt($currentYaml), $brief);
        $rawYaml = $this->stripFences($rawYaml);

        try {
            $parsed = Yaml::parse($rawYaml);
        } catch (ParseException $e) {
            logger()->error("YAML généré malformé\nErreur : {$e->getMessage()}\nYAML :\n" . substr($rawYaml, 0, 2000));
            throw new ScaffoldException('YAML invalide : ' . $e->getMessage(), $rawYaml);
        }

        // Auto-fix missing project_path
        if (is_array($parsed) && ! isset($parsed['project_path'])) {
            $parsed['project_path'] = '.';
        }

        if (! $this->yamlService->validate($parsed)) {
            logger()->error("Structure de workflow générée invalide\nYAML :\n" . substr($rawYaml, 0, 2000));
            throw new ScaffoldException('Structure de workflow invalide', $rawYaml);
        }

        return ['yaml' => $rawYaml, 'parsed' => $parsed];
    }

    private function buildSystemPrompt(?string $currentYaml = null): string
    {
        $docPath       = base_path('../docs/workflow-yaml-configuration.md');
        $documentation = '';
        if (is_file($docPath) && is_readable($docPath)) {
            $content = file_get_contents($docPath);
            if ($content !== false) {
                $documentation = $content;
            }
        }

        $refinementContext = '';
        if ($currentYaml) {
            $refinementContext = <<<CONTEXT

### CURRENT YAML WORKFLOW:
You MUST modify the existing YAML provided below according to the user's instructions.
Keep as much of the original structure as possible unless instructed otherwise.
Return the FULL updated YAML.

```yaml
{$currentYaml}
```
CONTEXT;
        }

        return <<<PROMPT
You are a YAML workflow generator for the "XuMaestro" system.
Respond with ONLY a valid YAML block. No explanation, no prose, no markdown fences.

### CRITICAL RULES:
1. The root MUST have: `name`, `project_path` (string), and `agents` (array).
2. DO NOT use `phases` or `tasks`. Use `agents` instead.
3. Each agent MUST have `id`, `engine` (gemini-cli or claude-code), and `steps` (array of strings).
4. `project_path` should be "." if not specified.
5. Configuration keys like `skippable`, `mandatory`, `timeout`, `interactive` MUST be top-level keys of the agent object, NOT inside the `steps` array.

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
{$refinementContext}

### GOAL:
Generate a valid YAML workflow for the following request:
PROMPT;
    }

    private function stripFences(string $yaml): string
    {
        return (string) preg_replace('/^```(?:yaml)?\s*\n?(.*?)\n?```\s*$/s', '$1', trim($yaml));
    }
}
