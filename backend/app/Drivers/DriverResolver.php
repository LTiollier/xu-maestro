<?php

namespace App\Drivers;

class DriverResolver
{
    public function __construct(
        private readonly ClaudeDriver $claude,
        private readonly GeminiDriver $gemini,
    ) {}

    public function for(string $engine): DriverInterface
    {
        return match($engine) {
            'claude-code' => $this->claude,
            'gemini-cli'  => $this->gemini,
            default       => throw new \InvalidArgumentException("Unsupported engine: {$engine}"),
        };
    }
}
