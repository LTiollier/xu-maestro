<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\InvalidJsonOutputException;

final class JsonOutputValidator
{
    public function validate(string $agentId, string $rawOutput): array
    {
        $decoded = json_decode(trim($rawOutput), true);

        if ($decoded === null || ! is_array($decoded)) {
            // Tentative d'extraction si du texte de narration pollue la sortie (ex: narration de tool use)
            // On cherche l'objet JSON le plus probable dans la chaîne (balancé en { })
            // Regex récursive pour les accolades balancées
            $regex = '/\{(?:[^{}]|(?R))*\}/s';
            if (preg_match_all($regex, $rawOutput, $matches)) {
                // On teste les candidats en partant de la fin (car l'agent répond souvent JSON à la fin)
                foreach (array_reverse($matches[0]) as $candidate) {
                    $test = json_decode($candidate, true);
                    if ($test !== null && is_array($test)) {
                        $decoded = $test;
                        break;
                    }
                }
            }
        }

        if ($decoded === null || ! is_array($decoded)) {
            throw new InvalidJsonOutputException($agentId, $rawOutput, 'Not valid JSON object');
        }

        $required = ['step', 'status', 'output', 'next_action', 'errors'];
        foreach ($required as $field) {
            if (! array_key_exists($field, $decoded)) {
                throw new InvalidJsonOutputException($agentId, $rawOutput, "Missing field: {$field}");
            }
        }

        if ($decoded['status'] === 'waiting_for_input') {
            // Fallback : certains modèles mettent la question dans "output" plutôt que "question"
            if ((! isset($decoded['question']) || $decoded['question'] === '')
                && isset($decoded['output']) && is_string($decoded['output']) && $decoded['output'] !== '') {
                $decoded['question'] = $decoded['output'];
            }
            if (! isset($decoded['question']) || ! is_string($decoded['question']) || $decoded['question'] === '') {
                throw new InvalidJsonOutputException($agentId, $rawOutput, "Missing field: question must be a non-empty string (required when status is waiting_for_input)");
            }
        }

        return $decoded;
    }
}
