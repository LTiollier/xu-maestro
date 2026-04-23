<?php

declare(strict_types=1);

namespace App\Services;

use App\Drivers\DriverResolver;
use App\Events\AgentBubble;
use Illuminate\Support\Facades\Process;

class GitService
{
    public function __construct(
        private readonly DriverResolver $driverResolver
    ) {}

    /**
     * Check if git checkpoint is enabled for the current agent.
     */
    public function isEnabled(array $workflow, array $agent): bool
    {
        // Global config
        $globalConfig = $workflow['git_checkpoints'] ?? [];
        $globalEnabled = $globalConfig['enabled'] ?? false;

        // Agent config
        $agentConfig = $agent['git_checkpoint'] ?? null;

        if ($agentConfig === null) {
            return (bool) $globalEnabled;
        }

        if (is_bool($agentConfig)) {
            return $agentConfig;
        }

        if (is_array($agentConfig)) {
            return (bool) ($agentConfig['enabled'] ?? $globalEnabled);
        }

        return false;
    }

    public function add(string $path): void
    {
        Process::path($path)->run('git add .');
    }

    public function hasChanges(string $path): bool
    {
        $result = Process::path($path)->run('git diff --cached --quiet');

        return $result->exitCode() !== 0;
    }

    public function commit(string $path, string $message): void
    {
        Process::path($path)->run(['git', 'commit', '-m', $message]);
    }

    public function generateCommitMessage(
        string $path,
        string $agentOutput,
        array $workflow,
        array $agent
    ): string {
        $globalConfig = $workflow['git_checkpoints'] ?? [];
        $agentConfig  = $agent['git_checkpoint'] ?? [];
        if (is_bool($agentConfig)) {
            $agentConfig = [];
        }

        $engine = $agentConfig['engine'] ?? $globalConfig['engine'] ?? $agent['engine'];
        $promptStyle = $agentConfig['prompt'] ?? $globalConfig['prompt'] ?? 'Utilise la convention gitmoji.';

        $diff = Process::path($path)->run('git diff --cached')->output();

        $systemPrompt = "Tu es un expert Git. Génère un message de commit pour les modifications suivantes.\n";
        $systemPrompt .= "PROMPT DE STYLE : {$promptStyle}\n\n";
        $systemPrompt .= "MODIFICATIONS (DIFF) :\n{$diff}\n\n";
        $systemPrompt .= "RÉSULTAT DE L'AGENT :\n{$agentOutput}\n\n";
        $systemPrompt .= "Réponds uniquement le message de commit, sans texte avant ou après.";

        $driver = $this->driverResolver->for($engine);
        
        // We use a simplified execute call without session history for message generation
        return trim($driver->execute($path, $systemPrompt, '', 60));
    }

    public function runCheckpoint(string $runId, string $path, array $workflow, array $agent, string $agentOutput, int $stepIndex): void
    {
        if (!$this->isEnabled($workflow, $agent)) {
            return;
        }

        // Verify if it's a git repo
        if (Process::path($path)->run('git rev-parse --is-inside-work-tree')->failed()) {
            return;
        }

        $this->add($path);

        if (!$this->hasChanges($path)) {
            return;
        }

        try {
            $message = $this->generateCommitMessage($path, $agentOutput, $workflow, $agent);
            $this->commit($path, $message);

            event(new AgentBubble($runId, $agent['id'], "📦 Commit : {$message}", $stepIndex, 'info'));
        } catch (\Throwable $e) {
            logger()->error('Git checkpoint failed', [
                'runId' => $runId,
                'agentId' => $agent['id'],
                'error' => $e->getMessage()
            ]);
            event(new AgentBubble($runId, $agent['id'], "⚠️ Échec du checkpoint Git : " . $e->getMessage(), $stepIndex, 'info'));
        }
    }
}
