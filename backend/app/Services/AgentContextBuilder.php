<?php

declare(strict_types=1);

namespace App\Services;

final class AgentContextBuilder
{
    public function build(string $context, array $agent, bool $nextIsSkippable = false, array $variables = []): string
    {
        $steps = $agent['steps'] ?? [];

        $result = $context;

        if (! empty($steps)) {
            $result .= "\n\n---\n## Task\n";
            foreach ($steps as $step) {
                $step = $this->interpolate($step, $variables);
                $result .= "- {$step}\n";
            }
        }

        $isInteractive = isset($agent['interactive']) && $agent['interactive'] === true;

        $result .= "\n\n---\n## Required output format\n"
            . "Respond with ONLY this JSON object — no markdown, no code block, no extra text:\n"
            . '{"step": "<brief description of what you did>", "status": "done", "output": "<your full response>", "next_action": null, "errors": []}';

        if ($nextIsSkippable) {
            $result .= "\n\nNote: set \"next_action\" to \"skip_next\" if you determine the next agent is not needed for this request.";
        }

        if ($isInteractive) {
            $result .= "\n\nIf you need clarification from the user before proceeding, use this format instead — execution will pause until the user answers:\n"
                . '{"step": "Asking user for clarification", "status": "waiting_for_input", "question": "<Write your question here — this exact text will be shown to the user>", "output": "", "next_action": null, "errors": []}' . "\n"
                . 'IMPORTANT: Put your question text in the "question" field, not in "output".';
        }

        return $result;
    }

    public function resolveSystemPrompt(array $agent, array $variables = []): string
    {
        $prompt = '';
        if (isset($agent['system_prompt']) && $agent['system_prompt'] !== '') {
            $prompt = $agent['system_prompt'];
        } elseif (isset($agent['system_prompt_file']) && $agent['system_prompt_file'] !== '') {
            $path = config('xu-maestro.prompts_path') . '/' . basename($agent['system_prompt_file']);
            if (file_exists($path)) {
                $content = file_get_contents($path);
                if ($content !== false) {
                    $prompt = $content;
                }
            }
        }

        return $this->interpolate($prompt, $variables);
    }

    private function interpolate(string $text, array $variables): string
    {
        foreach ($variables as $key => $value) {
            $text = str_replace('{{ ' . $key . ' }}', (string) $value, $text);
            $text = str_replace('{{' . $key . '}}', (string) $value, $text);
        }

        return $text;
    }
}
