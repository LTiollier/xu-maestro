<?php

namespace App\Services;

use App\Exceptions\YamlLoadException;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

class YamlService
{
    public function loadAll(): array
    {
        $path = config('xu-workflow.workflows_path');

        if (! is_string($path) || $path === '') {
            return [];
        }

        $files = glob($path . '/*.yaml') ?: [];
        $workflows = [];

        foreach ($files as $filePath) {
            try {
                $data = Yaml::parseFile($filePath);
                if ($this->validate($data)) {
                    $data['file'] = basename($filePath);
                    $workflows[] = $data;
                }
            } catch (ParseException) {
                // YAML malformé — exclure silencieusement
            } catch (\Throwable) {
                // Fichier illisible (permission denied, etc.) — exclure silencieusement
            }
        }

        return $workflows;
    }

    public function load(string $filename): array
    {
        $safe = basename($filename);
        $path = config('xu-workflow.workflows_path') . '/' . $safe;

        if (! file_exists($path)) {
            throw new YamlLoadException("Workflow file not found: {$safe}");
        }

        try {
            $data = Yaml::parseFile($path);
        } catch (ParseException $e) {
            throw new YamlLoadException("Invalid YAML in {$safe}: " . $e->getMessage());
        }

        if (! $this->validate($data)) {
            throw new YamlLoadException("Invalid workflow structure in {$safe}");
        }

        $data['file'] = $safe;

        return $data;
    }

    public function validate(mixed $data): bool
    {
        if (! is_array($data)) {
            return false;
        }

        if (! isset($data['name']) || ! is_string($data['name']) || $data['name'] === '') {
            return false;
        }

        if (! isset($data['project_path']) || ! is_string($data['project_path']) || $data['project_path'] === '') {
            return false;
        }

        if (! isset($data['agents']) || ! is_array($data['agents']) || count($data['agents']) === 0) {
            return false;
        }

        foreach ($data['agents'] as $agent) {
            if (! is_array($agent)) {
                return false;
            }
            if (! isset($agent['id']) || ! is_string($agent['id']) || $agent['id'] === '') {
                return false;
            }
            if (! isset($agent['engine']) || ! is_string($agent['engine']) || $agent['engine'] === '') {
                return false;
            }
            if (! in_array($agent['engine'], ['claude-code', 'gemini-cli', 'sub-workflow'], true)) {
                return false;
            }
            if ($agent['engine'] === 'sub-workflow') {
                if (! isset($agent['workflow_file']) || ! is_string($agent['workflow_file']) || $agent['workflow_file'] === '') {
                    return false;
                }
                $workflowsPath = config('xu-workflow.workflows_path');
                if (! is_string($workflowsPath) || $workflowsPath === '') {
                    return false;
                }
                $subFile = $workflowsPath . '/' . basename($agent['workflow_file']);
                if (! is_file($subFile)) {
                    return false;
                }
                try {
                    \Symfony\Component\Yaml\Yaml::parseFile($subFile);
                } catch (\Symfony\Component\Yaml\Exception\ParseException) {
                    return false;
                }
            }
        }

        return true;
    }
}
