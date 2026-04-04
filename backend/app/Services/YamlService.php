<?php

namespace App\Services;

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

    public function validate(mixed $data): bool
    {
        if (! is_array($data)) {
            return false;
        }

        if (! isset($data['name']) || ! is_string($data['name']) || $data['name'] === '') {
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
        }

        return true;
    }
}
