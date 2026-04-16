<?php

namespace App\Services;

use App\Support\SanitizesEnvCredentials;
use Illuminate\Support\Facades\File;

class ArtifactService
{
    use SanitizesEnvCredentials;

    public function __construct(
        private readonly CheckpointService $checkpointService,
    ) {}

    /**
     * Initialise le dossier run et crée les artefacts initiaux.
     *
     * Crée runs/YYYY-MM-DD-HHmm/ avec session.md, checkpoint.json et agents/.
     * Retourne le chemin absolu du dossier créé.
     */
    public function initializeRun(string $runId, string $workflowFile, string $brief): string
    {
        $folderName = now()->format('Y-m-d-His') . '-' . substr($runId, 0, 8);
        $runPath    = config('xu-workflow.runs_path') . '/' . $folderName;

        File::makeDirectory($runPath . '/agents', 0755, true, true);

        $header = "# Run: {$runId}\n"
            . "# Workflow: {$workflowFile}\n"
            . "# Brief: {$brief}\n"
            . "# Started: " . now()->toIso8601String() . "\n\n";

        // Sanitiser les credentials avant écriture (NFR12)
        File::put($runPath . '/session.md', $this->sanitizeEnvCredentials($header));

        $this->checkpointService->write($runPath, [
            'runId'           => $runId,
            'workflowFile'    => $workflowFile,
            'brief'           => $brief,
            'completedAgents' => [],
            'currentAgent'    => null,
            'currentStep'     => 0,
            'context'         => $runPath . '/session.md',
        ]);

        return $runPath;
    }

    /**
     * Ajoute l'output d'un agent à session.md (append-only) et le sauvegarde dans agents/{agentId}.md.
     *
     * Sanitise les credentials d'environnement avant toute écriture (NFR12).
     */
    public function appendAgentOutput(string $runPath, string $agentId, string $output): void
    {
        $sanitized = $this->sanitizeEnvCredentials($output);

        $section = "\n---\n## Agent: {$agentId}\n{$sanitized}\n";
        File::append($runPath . '/session.md', $section);

        File::put($runPath . '/agents/' . $agentId . '.md', $sanitized);
    }

    /**
     * Finalise un run en écrivant result.json avec le statut terminal, la durée et le nombre d'agents.
     *
     * Appelé par RunService à la fin de chaque exécution (completed, error, cancelled).
     * Sanitise les credentials avant écriture (NFR12).
     */
    public function finalizeRun(string $runPath, string $status, int $duration, int $agentCount): void
    {
        $data = [
            'status'      => $status,
            'duration'    => $duration,
            'agentCount'  => $agentCount,
            'completedAt' => now()->toIso8601String(),
        ];

        $json      = json_encode($data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        $sanitized = $this->sanitizeEnvCredentials($json);
        File::put($runPath . '/result.json', $sanitized);
    }

    /**
     * Lit et retourne le contenu courant de session.md.
     *
     * Ce contenu est passé comme $context au driver pour le prochain agent (FR15).
     */
    public function getContextContent(string $runPath): string
    {
        return File::get($runPath . '/session.md');
    }

}
