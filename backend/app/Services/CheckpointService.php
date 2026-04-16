<?php

namespace App\Services;

use App\Support\SanitizesEnvCredentials;
use Illuminate\Support\Facades\File;

class CheckpointService
{
    use SanitizesEnvCredentials;

    /**
     * Écrit checkpoint.json avec sanitisation des credentials (NFR12).
     * À appeler AVANT d'émettre l'événement 'done' (NFR6).
     */
    public function write(string $runPath, array $data): void
    {
        $json      = json_encode($data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        $sanitized = $this->sanitizeEnvCredentials($json);
        File::put($runPath . '/checkpoint.json', $sanitized);
    }

    /**
     * Lit checkpoint.json et retourne l'array complet.
     * Lance \RuntimeException si fichier absent ou JSON invalide.
     * Utilisé pour la reprise depuis un checkpoint (Story 3.4).
     */
    public function read(string $runPath): array
    {
        $path = $runPath . '/checkpoint.json';

        if (! File::exists($path)) {
            throw new \RuntimeException("Checkpoint not found: {$path}");
        }

        $decoded = json_decode(File::get($path), true);

        if (! is_array($decoded)) {
            throw new \RuntimeException("Invalid checkpoint JSON: {$path}");
        }

        return $decoded;
    }

}
