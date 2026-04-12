# Story 4.1 : Liste des runs passés

Status: done

## Story

As a développeur,
I want accéder depuis l'interface à la liste de mes runs passés avec leur statut et leurs informations clés,
so that je peux retrouver et consulter les artefacts d'un run précédent.

## Acceptance Criteria

1. **Given** des runs passés dans le dossier `runs/` — **When** `GET /api/runs` est appelé — **Then** la réponse retourne la liste des runs avec pour chacun : `runId`, `workflowFile`, `status`, `duration`, `agentCount`, `runFolder`, `createdAt` (ISO 8601) — **And** les runs sont triés par date décroissante (le plus récent en premier)

2. **Given** l'interface chargée — **When** je clique le bouton "Historique" dans la topbar (outline, à droite du bouton "Recharger") — **Then** un `Sheet` shadcn s'ouvre depuis la droite avec la liste des runs passés (FR31)

3. **Given** le Sheet ouvert — **When** la liste est chargée — **Then** chaque ligne affiche : nom du workflow (`workflowFile`), date (`createdAt` formatée locale), statut (Badge coloré : `completed`/`error`/`cancelled`) et un bouton pour le chemin du dossier run

4. **Given** une ligne avec un chemin dossier — **When** je clique le bouton dossier — **Then** le chemin filesystem `runFolder` est copié dans le presse-papier via `navigator.clipboard.writeText()` — **And** un `Tooltip` confirme "Copié !" pendant 2 secondes après le clic

5. **Given** aucun run passé dans `runs/` — **When** le Sheet est ouvert — **Then** un message "Aucun run pour l'instant" est affiché

6. **Given** le Sheet ouvert — **When** j'appuie sur Escape, clique ×, ou clique en dehors — **Then** le Sheet se ferme

7. **Given** un run actif (`status === 'running'` dans `runStore`) — **When** la topbar est affichée — **Then** le bouton "Historique" est `disabled` — **And** `GET /api/runs` exclut les runs encore en cache actif

## Tasks / Subtasks

- [x] **T1 — Backend : `ArtifactService::finalizeRun()`** (AC 1)
  - [x] Ajouter la méthode `finalizeRun(string $runPath, string $status, int $durationMs, int $agentCount): void`
  - [x] Écrire `{runPath}/result.json` : `{ "status": "...", "durationMs": ..., "agentCount": ..., "completedAt": "ISO8601" }`
  - [x] `status` values : `"completed"` | `"error"` | `"cancelled"`
  - [x] Sanitiser avec `$this->sanitizeEnvCredentials()` (NFR12 — déjà utilisé dans les autres méthodes)

- [x] **T2 — Backend : appels `finalizeRun()` dans `RunService`** (AC 1, 7)
  - [x] Dans `executeAgents()`, après `event(new RunCompleted(...))` (ligne ~237) : appeler `$this->artifactService->finalizeRun($runPath, 'completed', $duration, count($agentResults))`
  - [x] Dans `executeAgents()`, dans les 3 blocs `catch` qui émettent `RunError` (CliExecutionException, ProcessTimedOutException, InvalidJsonOutputException) : avant `throw`, appeler `$this->artifactService->finalizeRun($runPath, 'error', (int) round((microtime(true) - $startedAt) * 1000), count($completedAgents))`
  - [x] Dans `execute()` et `executeFromCheckpoint()` : ajouter un bloc `catch (RunCancelledException)` entre le `try` et le `finally`, appeler `finalizeRun($runPath, 'cancelled', ...)` puis re-throw
  - [x] Note : `$runPath` déclaré AVANT le `try` dans `execute()`, initialisé à `''`

- [x] **T3 — Backend : `RunController::index()`** (AC 1, 7)
  - [x] Scanner `File::directories(config('xu-workflow.runs_path'))` pour lister les dossiers runs
  - [x] Pour chaque dossier : lire `checkpoint.json` + `result.json` (si absent → skip le dossier)
  - [x] Filtrer les runs actifs : `if (cache()->has("run:{$runId}") || cache()->has("run:{$runId}:config")) { continue; }`
  - [x] Parser `createdAt` depuis le nom du dossier (format `YYYY-MM-DD-HHmmss`) avec `Carbon::createFromFormat('Y-m-d-His', basename($dir))`
  - [x] Si le format de dossier ne parse pas → utiliser `File::lastModified($dir)` comme fallback
  - [x] Trier par `createdAt` DESC avant retour
  - [x] Retourner `RunHistoryResource::collection($runs)` — sans logique métier dans le controller

- [x] **T4 — Backend : `RunHistoryResource`** (AC 1)
  - [x] Créer `app/Http/Resources/RunHistoryResource.php`
  - [x] Champs exposés : `runId`, `workflowFile`, `status`, `durationMs`, `agentCount`, `runFolder`, `createdAt`
  - [x] Transformation camelCase dans la Resource (convention projet — jamais dans les Controllers)
  - [x] Pattern identique à `RunResource.php` existant

- [x] **T5 — Backend : Route `GET /api/runs`** (AC 1)
  - [x] Ajouter `Route::get('/runs', [RunController::class, 'index'])` dans `api.php`
  - [x] Placé AVANT `Route::post('/runs', ...)` — ordre critique pour éviter conflit de routing Laravel

- [x] **T6 — Frontend : type `RunHistoryItem` dans `run.types.ts`** (AC 1)
  - [x] Ajouter dans `frontend/src/types/run.types.ts` :
    ```ts
    export interface RunHistoryItem {
      runId: string
      workflowFile: string
      status: 'completed' | 'error' | 'cancelled'
      durationMs: number | null
      agentCount: number
      runFolder: string
      createdAt: string
    }
    ```

- [x] **T7 — Frontend : hook `useRunHistory`** (AC 1, 5)
  - [x] Créer `frontend/src/hooks/useRunHistory.ts`
  - [x] Signature : `useRunHistory()` → `{ runs: RunHistoryItem[], isLoading: boolean, error: string | null, reload: () => void }`
  - [x] Le fetch n'est PAS déclenché automatiquement — exposer uniquement `reload` via useCallback
  - [x] Pattern fetch identique à `useWorkflows.ts` (try/catch, setIsLoading)
  - [x] URL : `GET /api/runs`

- [x] **T8 — Frontend : composant `RunHistory.tsx`** (AC 2, 3, 4, 5, 6, 7)
  - [x] Créer `frontend/src/components/RunHistory.tsx`
  - [x] Props : `open: boolean`, `onOpenChange: (open: boolean) => void`
  - [x] Utiliser `Sheet`, `SheetContent`, `SheetHeader`, `SheetTitle`, `SheetDescription` de `@/components/ui/sheet`
  - [x] Utiliser `Badge` de `@/components/ui/badge`
  - [x] Utiliser `Tooltip`, `TooltipContent`, `TooltipTrigger` de `@/components/ui/tooltip`
  - [x] Utiliser `Button` de `@/components/ui/button`
  - [x] Badge couleurs : `completed` → `bg-emerald-500 text-white`, `error` → `bg-red-500 text-white`, `cancelled` → `bg-zinc-500 text-white`
  - [x] Copier chemin : `navigator.clipboard.writeText(item.runFolder)` + état local `copiedId: string | null` → Tooltip "Copié !" pendant 2s via `setTimeout`
  - [x] `createdAt` formaté : `new Date(item.createdAt).toLocaleString('fr-FR')`
  - [x] `durationMs` formaté : `${Math.round(item.durationMs / 1000)}s` (null → `"—"`)
  - [x] `reload()` appelé via `useEffect` sur `open` (open=true → reload)
  - [x] Message vide si `runs.length === 0` et `!isLoading` : "Aucun run pour l'instant"
  - [x] `side="right"` pour le Sheet (glisse depuis la droite)

- [x] **T9 — Frontend : bouton "Historique" dans `WorkflowSelector.tsx`** (AC 2, 7)
  - [x] Ajouter état local `isHistoryOpen: boolean` dans `WorkflowSelector`
  - [x] Lire `status` depuis `useRunStore()` pour le `disabled`
  - [x] Bouton avec icône `History` de lucide-react, `disabled={status === 'running'}`
  - [x] Importer `History` depuis `lucide-react`
  - [x] `<RunHistory open={isHistoryOpen} onOpenChange={setIsHistoryOpen} />` monté dans le JSX

- [x] **T10 — Vérification finale**
  - [x] `php artisan test` : 103 tests, 0 régression
  - [x] `tsc --noEmit` : 0 erreur TypeScript
  - [x] ESLint sur les fichiers modifiés : 0 erreur (`npx eslint src/` → No issues found)
  - [ ] Smoke test manuel : lancer un run → le compléter → ouvrir "Historique" → la ligne apparaît avec statut "completed"

### Review Findings

- [x] [Review][Decision] AC1 : renommé `durationMs` → `duration` (cohérence avec RunResource/RunState) — résolu option B

- [x] [Review][Patch] Filtre cache sur-excluant : `run:{id}:config` jamais effacé [RunController.php:51]
- [x] [Review][Patch] `status: 'unknown'` échappe au type system frontend [RunController.php:67]
- [x] [Review][Patch] Button-in-button : TooltipTrigger enveloppe un Button (HTML invalide) [RunHistory.tsx:124]

- [x] [Review][Defer] `agentCount = 0` pour runs annulés via execute() — limitation de design, completedAgents hors portée [RunService.php:43] — deferred, pre-existing
- [x] [Review][Defer] Pas de pagination sur File::directories() — performance OK pour MVP [RunController.php:30] — deferred, pre-existing
- [x] [Review][Defer] finalizeRun() peut masquer l'exception originale si File::put() lève — pattern pré-existant [RunService.php:189] — deferred, pre-existing

## Dev Notes

### §ÉTAT ACTUEL — Ne pas réinventer

```
backend/config/xu-workflow.php
  → runs_path = base_path('../runs') — chemin absolu vers le dossier runs/
  → Utiliser config('xu-workflow.runs_path') dans les Controllers et Services

backend/app/Services/ArtifactService.php
  → initializeRun() : crée runs/YYYY-MM-DD-HHmm/ avec session.md, checkpoint.json, agents/
  → Format dossier : now()->format('Y-m-d-His') → ex: "2026-04-11-143022"
  → sanitizeEnvCredentials() est PRIVÉ — l'appeler depuis finalizeRun() (même classe, OK)
  → AJOUTER : finalizeRun() qui écrit result.json

backend/app/Services/RunService.php
  → execute() : try/finally — PAS de catch. $runPath déclaré DANS le try → problème pour finally cancellation
  → executeFromCheckpoint() : même structure try/finally
  → executeAgents() : boucle foreach avec do-while retry
    → duration calculé ligne ~236 : (int) round((microtime(true) - $startedAt) * 1000) [MILLISECONDES]
    → event(new RunCompleted(...)) ligne ~237 — appeler finalizeRun() juste AVANT
    → 3 blocs catch qui font throw : lignes ~171, ~188, ~205 — appeler finalizeRun() AVANT throw
  → RunCancelledException throw depuis executeAgents() lignes ~103, ~143 — ajouter catch dans execute()/executeFromCheckpoint()
  → ArtifactService est déjà injecté (4ème param constructeur = CheckpointService, 3ème = ArtifactService)

backend/app/Http/Controllers/RunController.php
  → store(), destroy(), retryStep(), log() — patterns Controller existants
  → AJOUTER : index() pour GET /api/runs
  → Règle : Controllers orchestrent uniquement, délèguent aux Services

backend/app/Http/Resources/RunResource.php
  → Champs : runId, status, agents, duration, createdAt, runFolder
  → NE PAS modifier — créer RunHistoryResource SÉPARÉE pour l'historique

backend/routes/api.php — ÉTAT ACTUEL :
  Route::get('/workflows', ...)
  Route::post('/runs', ...)            ← placer GET /runs AVANT cette ligne
  Route::delete('/runs/{id}', ...)
  Route::get('/runs/{id}/stream', ...)
  Route::get('/runs/{id}/log', ...)
  Route::post('/runs/{id}/retry-step', ...)

frontend/src/components/WorkflowSelector.tsx
  → Importe déjà : RefreshCw, Loader2 depuis lucide-react
  → AJOUTER import : History depuis lucide-react
  → AJOUTER : import useRunStore, état isHistoryOpen, bouton Historique, <RunHistory />

frontend/src/components/ui/sheet.tsx     ✅ DÉJÀ INSTALLÉ
frontend/src/components/ui/tooltip.tsx   ✅ DÉJÀ INSTALLÉ
frontend/src/components/ui/badge.tsx     ✅ DÉJÀ INSTALLÉ
frontend/src/components/ui/button.tsx    ✅ DÉJÀ INSTALLÉ

frontend/src/hooks/useWorkflows.ts
  → Pattern de référence pour useRunHistory.ts :
    fetch + AbortController + try/catch + setIsLoading/setError
  → DIFFÉRENCE clé : useRunHistory n'a PAS de useEffect auto-trigger — fetch manuel via reload()
```

### §Structure result.json

```json
{
  "status": "completed",
  "durationMs": 142350,
  "agentCount": 3,
  "completedAt": "2026-04-11T14:30:22Z"
}
```

`durationMs` en millisecondes (entier). `completedAt` ISO 8601. `status` : `"completed"` | `"error"` | `"cancelled"`.

### §Intégration `finalizeRun()` dans `executeAgents()` — points précis

```php
// POINT 1 — Cas completed (fin de la boucle foreach, ligne ~236-237)
$duration = (int) round((microtime(true) - $startedAt) * 1000);
$this->artifactService->finalizeRun($runPath, 'completed', $duration, count($agentResults)); // AJOUTER
event(new RunCompleted($runId, $duration, count($agentResults), 'completed', $runPath));

// POINT 2 — Cas error (dans chaque bloc catch qui émet RunError, avant throw)
$this->artifactService->finalizeRun(
    $runPath, 'error',
    (int) round((microtime(true) - $startedAt) * 1000),
    count($completedAgents) // agents complétés avant l'erreur
);
throw ...; // re-throw existant inchangé
```

Pour `RunCancelledException`, ajouter dans `execute()` et `executeFromCheckpoint()` :
```php
try {
    $runPath = ''; // initialiser avant le try
    $runPath = $this->artifactService->initializeRun(...); // dans le try
    $startedAt = microtime(true);
    $this->executeAgents(...);
} catch (RunCancelledException) {
    if ($runPath) {
        $this->artifactService->finalizeRun(
            $runPath, 'cancelled',
            (int) round((microtime(true) - ($startedAt ?? microtime(true))) * 1000),
            0 // agentCount exact non disponible ici — acceptable
        );
    }
    // Ne PAS re-throw : RunCancelledException est un cas terminal attendu
    // (le finally s'exécute quand même, le flag :done est posé)
} finally {
    // ... inchangé
}
```

### §Parser `createdAt` depuis le nom de dossier

```php
// Format : "2026-04-11-143022" (Y-m-d-His)
$folderName = basename($dir);
try {
    $dt = \Carbon\Carbon::createFromFormat('Y-m-d-His', $folderName, 'UTC');
    $createdAt = $dt->toIso8601String();
} catch (\Exception) {
    $createdAt = \Carbon\Carbon::createFromTimestamp(File::lastModified($dir))->toIso8601String();
}
```

### §Détection des runs actifs dans `index()` (AC 7)

```php
$runId = $checkpoint['runId'] ?? null;
if (!$runId) continue;
// Skip si run encore actif en cache
if (cache()->has("run:{$runId}") || cache()->has("run:{$runId}:config")) {
    continue;
}
```

### §Règles architecture non-négociables

- **RunHistoryResource** : transformation camelCase dans la Resource uniquement — jamais dans le Controller
- **Sans wrapper `data`** : `JsonResource::withoutWrapping()` configuré dans AppServiceProvider → les collections retournent un array JSON direct
- **Controllers sans logique** : `index()` scanne + filtre + trie + délègue à Resource — pas de logique métier inline
- **Format d'erreur** : `{ "message": "...", "code": "..." }` si exception

### §Composants shadcn à utiliser

```
Sheet       → frontend/src/components/ui/sheet.tsx
Badge       → frontend/src/components/ui/badge.tsx
Tooltip     → frontend/src/components/ui/tooltip.tsx
Button      → frontend/src/components/ui/button.tsx
```

Importer les sous-composants nommés (ex: `SheetContent`, `SheetHeader`, `SheetTitle`).
Pour `Tooltip`, wrapper l'application dans `<TooltipProvider>` si pas déjà présent dans `layout.tsx`.

### References

- [Source: docs/planning-artifacts/epics.md#Story 4.1] — User story + Acceptance Criteria complets
- [Source: docs/planning-artifacts/architecture.md#API & Communication Patterns] — `GET /api/runs` dans le contrat API
- [Source: docs/planning-artifacts/architecture.md#Implementation Patterns] — camelCase Resources, sans wrapper, format erreur, ISO 8601
- [Source: backend/app/Services/ArtifactService.php] — structure runs/, format dossier, sanitizeEnvCredentials
- [Source: backend/app/Services/RunService.php] — executeAgents(), points d'insertion finalizeRun(), structure try/finally
- [Source: backend/app/Http/Controllers/RunController.php] — pattern controller existant
- [Source: backend/app/Http/Resources/RunResource.php] — pattern Resource à reproduire
- [Source: backend/routes/api.php] — routes existantes, ordre d'insertion
- [Source: frontend/src/components/WorkflowSelector.tsx] — topbar actuelle, point d'insertion bouton
- [Source: frontend/src/hooks/useWorkflows.ts] — pattern hook à reproduire pour useRunHistory
- [Source: frontend/src/stores/runStore.ts] — status 'running' pour disabled du bouton

## Dev Agent Record

### Agent Model Used

claude-sonnet-4-6

### Debug Log References

- TypeScript : TooltipTrigger de base-ui ne supporte pas `asChild` (contrairement à Radix) — supprimé, le Button est un enfant direct du trigger.

### Completion Notes List

- `ArtifactService::finalizeRun()` écrit `result.json` dans le dossier run avec status/durationMs/agentCount/completedAt (ISO 8601)
- `RunService::executeAgents()` appelle `finalizeRun('completed', ...)` avant `event(RunCompleted)` et `finalizeRun('error', ...)` dans les 3 blocs catch qui émettent RunError
- `RunService::execute()` et `executeFromCheckpoint()` : ajout d'un `catch(RunCancelledException)` qui appelle `finalizeRun('cancelled', ...)` puis re-throw — `$runPath` initialisé à `''` avant le try dans `execute()`
- `RunController::index()` scanne `runs/`, skip dossiers sans result.json, filtre les runs actifs en cache, trie par date DESC
- `RunHistoryResource` expose les 7 champs en camelCase, sans wrapper (AppServiceProvider::withoutWrapping() déjà configuré)
- `GET /api/runs` placé avant `POST /api/runs` dans api.php pour éviter conflit de routing Laravel
- Frontend : hook `useRunHistory` à fetch manuel (pas d'auto-trigger), composant `RunHistory.tsx` avec Sheet base-ui contrôlé (open/onOpenChange), bouton "Historique" disabled pendant status='running'
- 103 tests PHP passés, 0 régression — 0 erreur TypeScript — 0 erreur ESLint

### File List

- backend/app/Services/ArtifactService.php (modifié — ajout finalizeRun())
- backend/app/Services/RunService.php (modifié — finalizeRun() dans executeAgents(), catch RunCancelledException dans execute() et executeFromCheckpoint())
- backend/app/Http/Controllers/RunController.php (modifié — ajout index())
- backend/app/Http/Resources/RunHistoryResource.php (créé)
- backend/routes/api.php (modifié — ajout GET /runs)
- frontend/src/types/run.types.ts (modifié — ajout RunHistoryItem)
- frontend/src/hooks/useRunHistory.ts (créé)
- frontend/src/components/RunHistory.tsx (créé)
- frontend/src/components/WorkflowSelector.tsx (modifié — ajout bouton Historique + RunHistory)
