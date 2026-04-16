<?php

declare(strict_types=1);

namespace App\Support;

trait SanitizesEnvCredentials
{
    /**
     * Redacte les valeurs des variables d'environnement qui ressemblent à des credentials (NFR12).
     *
     * Règle : strlen($value) >= 8 ET le nom de la variable contient key|token|secret|password|credential|api.
     */
    private function sanitizeEnvCredentials(string $content): string
    {
        $env = array_merge($_ENV, getenv() ?: []);

        foreach ($env as $key => $value) {
            $value = (string) $value;
            if (
                strlen($value) >= 8
                && preg_match('/key|token|secret|password|credential|api/i', (string) $key)
            ) {
                $content = str_replace($value, '[REDACTED]', $content);
            }
        }

        return $content;
    }
}
