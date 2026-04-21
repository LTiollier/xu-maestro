<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\SanitizesEnvCredentials;
use Illuminate\Support\Facades\File;

final class ArtifactService
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
        $runPath    = config('xu-maestro.runs_path') . '/' . $folderName;

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

    /**
     * Écrit l'output d'un agent parallèle dans un fichier temporaire isolé.
     * Utilisé pendant l'exécution du groupe pour éviter les collisions d'écriture sur session.md.
     */
    public function writeAgentTempOutput(string $runPath, string $agentId, string $content): void
    {
        $sanitized = $this->sanitizeEnvCredentials($content);
        File::makeDirectory($runPath . '/tmp', 0755, true, true);
        File::put($runPath . '/tmp/' . $agentId . '.md', $sanitized);
    }

    /**
     * Fusionne les fichiers temporaires des agents parallèles dans session.md.
     * L'ordre de fusion suit l'ordre de déclaration YAML ($agentIds) pour garantir le déterminisme.
     * Les fichiers temporaires sont supprimés après fusion.
     */
    public function mergeParallelOutputs(string $runPath, array $agentIds, string $contextPath): void
    {
        foreach ($agentIds as $agentId) {
            $tmpPath = $runPath . '/tmp/' . $agentId . '.md';
            if (! File::exists($tmpPath)) {
                continue;
            }
            $content = File::get($tmpPath);
            $section = "\n---\n## Agent: {$agentId}\n{$content}\n";
            File::append($contextPath, $section);
            File::put($runPath . '/agents/' . $agentId . '.md', $content);
            File::delete($tmpPath);
        }
    }

}
