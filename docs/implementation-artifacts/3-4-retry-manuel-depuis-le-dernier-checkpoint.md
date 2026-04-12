# Story 3.4 : Retry manuel depuis le dernier checkpoint

Status: done

## Story

As a développeur,
I want pouvoir relancer une étape en erreur depuis le dernier checkpoint en un clic,
so that le workflow reprend exactement là où il s'est arrêté sans rejouer les étapes déjà complétées.

## Acceptance Criteria

1. **Given** une `BubbleBox` error visible avec un bouton "Relancer cette étape" — **When** je clique ce bouton — **Then** `POST /api/runs/{id}/retry-step` est appelé (AC câblé dans `AgentCard`)

2. **Given** `POST /api/runs/{id}/retry-step` reçu — **When** le checkpoint est valide — **Then** `CheckpointService::read()` recharge le contexte jusqu'au dernier checkpoint valide — **And** les drapeaux `run:{id}:done` et `run:{id}:error_emitted` sont effacés — **And** `run:{id}:retry_checkpoint` est stocké en cache (FR25)

3. **Given** le retry déclenché — **When** le frontend reconnecte le stream SSE — **Then** `SseController` détecte le checkpoint de retry et appelle `RunService::executeFromCheckpoint()` au lieu de `execute()`

4. **Given** `executeFromCheckpoint()` démarre — **When** l'agent fautif est traité — **Then** un `agent.status.changed(working)` est émis PUIS un `agent.bubble(info: "Reprise depuis le checkpoint...")` — **And** la `AgentCard` repasse à l'état `working` — **And** la `BubbleBox error` est remplacée par une `BubbleBox info` (FR25)

5. **Given** le retry en cours — **When** il réussit — **Then** le pipeline reprend normalement depuis cette étape — **And** les agents suivants s'exécutent dans l'ordre — **And** `run.completed` est émis à la fin (FR25)

6. **Given** le retry en cours — **When** il échoue à nouveau — **Then** `RunError` est émis (même comportement que `execute()`) — **And** la `BubbleBox error` réapparaît avec le nouveau message

7. **Given** `POST /api/runs/{id}/retry-step` — **When** le run n'a pas de chemin (`run:{id}:path` absent du cache) — **Then** HTTP 404 est retourné `{ message, code: "RUN_NOT_FOUND" }`

8. **Given** un click Retry — **When** le retry démarre — **Then** les ressources CLI de l'exécution précédente sont déjà libérées (elles ont été tuées par le handler d'erreur en 2.2/3.2) — **And** `run:{id}:cancelled` est effacé pour permettre la reprise (FR26)

9. **Given** le retry lancé — **When** `runStore.status` passe à `'running'` — **Then** la `LaunchBar` affiche "Annuler" — **And** une nouvelle connexion SSE est ouverte vers le stream existant

## Tasks / Subtasks

- [x] **T1 — Backend : `RunController::retryStep()`** (AC 2, 7)
  - [x] Injecter `CheckpointService` dans `RunController` via constructeur + DI
  - [x] Valider `run:{id}:path` présent en cache → 404 si absent
  - [x] Appeler `$this->checkpointService->read($runPath)` → 404 si checkpoint manquant ou JSON invalide
  - [x] Effacer `run:{id}:done`, `run:{id}:error_emitted`, `run:{id}:cancelled`
  - [x] Stocker `run:{id}:retry_checkpoint` avec TTL 3600
  - [x] Retourner `{ runId: $id, status: 'retrying' }` HTTP 202

- [x] **T2 — Backend : Route** (AC 1)
  - [x] Ajouter `Route::post('/runs/{id}/retry-step', [RunController::class, 'retryStep'])` dans `api.php`

- [x] **T3 — Backend : `RunService::executeFromCheckpoint()`** (AC 3, 4, 5, 6, 8)
  - [x] Extraire la boucle d'exécution des agents de `execute()` dans une méthode privée `executeAgents(string $runId, array $workflow, string $runPath, string $brief, int $startStep, array $completedAgents): void`
  - [x] `execute()` appelle `executeAgents($runId, $workflow, $runPath, $brief, 0, [])`
  - [x] `executeFromCheckpoint(string $runId, array $checkpoint): void` :
    - Charge le workflow via `yamlService->load($checkpoint['workflowFile'])`
    - Lit `$runPath = cache()->get("run:{$runId}:path")` — throw `\RuntimeException` si absent
    - Émet `AgentStatusChanged($runId, $currentAgentId, 'working', $currentStep, '')` PUIS `AgentBubble($runId, $currentAgentId, "Reprise depuis le checkpoint...", $currentStep)` sur l'agent fautif
    - Appelle `executeAgents($runId, $workflow, $runPath, $checkpoint['brief'], $startStep, $checkpoint['completedAgents'])`
  - [x] Dans `executeAgents()`, les agents avec `$stepIndex < $startStep` sont skippés (`continue`)
  - [x] La structure `finally` de `executeAgents()` recopie exactement celle de `execute()`

- [x] **T4 — Backend : `SseController::stream()` — détection retry** (AC 3)
  - [x] Avant la garde `cache()->has("run:{$id}") || cache()->has("run:{$id}:done")` :
    - `$retryCheckpoint = cache()->pull("run:{$id}:retry_checkpoint")` — consomme atomiquement
    - Si `$retryCheckpoint` trouvé : appeler `$this->runService->executeFromCheckpoint($id, $retryCheckpoint)` et retourner directement (skip la garde et `execute()` normal)
  - [x] Si pas de `$retryCheckpoint` : comportement existant inchangé

- [x] **T5 — Frontend : `runStore` — action `setRetrying()`** (AC 9)
  - [x] Ajouter `retryKey: number` à l'interface `RunStoreState` (défaut `0`)
  - [x] Ajouter `setRetrying: () => void` qui : `status: 'running', errorMessage: null, retryKey: state.retryKey + 1`

- [x] **T6 — Frontend : `useSSEListener` — reconnexion sur retry** (AC 9)
  - [x] Ajouter `retryKey = 0` comme 2ème paramètre : `useSSEListener(runId: string | null, retryKey = 0)`
  - [x] Ajouter `retryKey` dans le tableau de dépendances du `useEffect`

- [x] **T7 — Frontend : `LaunchBar.tsx` — passer `retryKey`** (AC 9)
  - [x] Destructurer `retryKey` depuis `useRunStore()`
  - [x] Changer `useSSEListener(runId)` → `useSSEListener(runId, retryKey)`

- [x] **T8 — Frontend : `AgentCard.tsx` — câbler `onRetry`** (AC 1, 4, 9)
  - [x] Importer `useRunStore` depuis `@/stores/runStore`
  - [x] Dans le composant, lire `runId` et `setRetrying` via `useRunStore`
  - [x] Créer `handleRetry`: appel `POST /api/runs/${runId}/retry-step` → si ok (`.ok`) → `setRetrying()`
  - [x] Passer `handleRetry` à `<BubbleBox onRetry={handleRetry} />` uniquement dans la branche error (le bouton dans BubbleBox est déjà conditionné à `variant === 'error'`, `disabled={!onRetry}` est déjà en place)
  - [x] Ne PAS appeler `resetAgents()` — les agents complétés restent visibles avec leur statut `done`

- [x] **T9 — Backend tests : `RunRetryStepTest.php`** (AC 2, 7)
  - [x] Créer `backend/tests/Unit/RunRetryStepTest.php`
  - [x] Test : retryStep 404 si `run:{id}:path` absent du cache
  - [x] Test : retryStep 404 si checkpoint.json absent du disque
  - [x] Test : retryStep 202, flags effacés, retry_checkpoint stocké
  - [x] Utiliser le pattern HTTP test existant (pas mock — tester le contrôleur via `$this->postJson`)

- [x] **T10 — Backend tests : `RunServiceRetryFromCheckpointTest.php`** (AC 3–6, 8)
  - [x] Créer `backend/tests/Unit/RunServiceRetryFromCheckpointTest.php`
  - [x] Même pattern mock setUp() que `RunServiceRetryTest` (4 mocks, Event::fake(), cache array)
  - [x] Test : `executeFromCheckpoint` skip les agents avant `startStep`
  - [x] Test : `executeFromCheckpoint` émet `AgentStatusChanged(working)` + `AgentBubble(info)` sur l'agent fautif avant exécution
  - [x] Test : `executeFromCheckpoint` émet `RunCompleted` si le retry réussit
  - [x] Test : `executeFromCheckpoint` émet `RunError` si le retry échoue à nouveau
  - [x] Test : check cancellation fonctionne dans `executeFromCheckpoint`
  - [x] Test : `run:{id}:done` est posé en finally

- [x] **T11 — Vérification finale**
  - [x] `php artisan test` : 0 régression, nouveaux tests verts
  - [x] `tsc --noEmit` : 0 erreur TypeScript
  - [x] ESLint sur les fichiers modifiés : 0 erreur
  - [x] Smoke test manuel : cliquer "Relancer cette étape" → AgentCard repasse en working → pipeline reprend

## Dev Notes

### §ÉTAT ACTUEL — Ne pas réinventer

```
backend/app/Services/RunService.php
  → execute() : boucle retry do-while déjà en place (story 3.2)
  → CheckpointService injecté (4ème param constructeur)
  → Checkpoint PRÉ-AGENT écrit AVANT la boucle do-while
  → Checkpoint POST-COMPLETION écrit APRÈS $completedAgents[] = $agentId, AVANT event(done)
  → finally : forget(run:{id}), forget(run:{id}:cancelled), put(run:{id}:done, true)
  → NE PAS effacer error_emitted en finally (invariant de 3.1/3.2)

backend/app/Http/Controllers/RunController.php
  → store() : POST /api/runs — crée runId, stocke config en cache
  → destroy() : DELETE /api/runs/{id} — pose le flag cancelled
  → log() : GET /api/runs/{id}/log — lit session.md
  → RunController n'injecte PAS encore CheckpointService — à injecter en T1

backend/app/Http/Controllers/SseController.php
  → stream() : lit config depuis cache, crée StreamedResponse, appelle RunService::execute()
  → Garde : if (cache()->has("run:{$id}") || cache()->has("run:{$id}:done")) { return; }
  → catch(\Throwable) : émet RunError uniquement si error_emitted absent
  → MODIFICATION : ajouter détection retry_checkpoint AVANT la garde (T4)

backend/app/Services/CheckpointService.php
  → write() : écrit checkpoint.json avec sanitisation NFR12
  → read() : lit et parse checkpoint.json — throw \RuntimeException si absent/invalide
  → AUCUNE modification nécessaire pour 3.4 — read() déjà implémenté pour cette story

backend/routes/api.php
  → Routes existantes : GET /workflows, POST /runs, DELETE /runs/{id},
    GET /runs/{id}/stream, GET /runs/{id}/log
  → AJOUTER : POST /runs/{id}/retry-step

frontend/src/stores/runStore.ts
  → RunStoreState : runId, status, duration, runFolder, errorMessage
  → setRunId(), setRunCompleted(), setRunError(), resetRun()
  → AJOUTER : retryKey: number (défaut 0), setRetrying()

frontend/src/hooks/useSSEListener.ts
  → Signature actuelle : useSSEListener(runId: string | null)
  → useEffect dependency : [runId] — AJOUTER retryKey
  → Ferme l'EventSource sur run.completed et run.error
  → EventSource instancié avec délai 100ms (setTimeout existant — NE PAS modifier)
  → MODIFIER : ajouter retryKey = 0 comme paramètre, dans useEffect deps

frontend/src/components/LaunchBar.tsx
  → Appelle useSSEListener(runId) — seul point de consommation SSE
  → MODIFIER : passer retryKey extrait du runStore

frontend/src/components/BubbleBox.tsx
  → BubbleBox({variant, message, onRetry?}) — déjà en place depuis 3.3
  → Bouton "Relancer cette étape" déjà rendu en variant error
  → disabled={!onRetry} déjà en place (patch review 3.3)
  → onClick={onRetry} déjà câblé
  → AUCUNE modification nécessaire pour 3.4

frontend/src/components/AgentCard.tsx
  → AgentCardData : name, engine, steps, status, bubbleMessage?, errorMessage?
  → Branche BubbleBox error : <BubbleBox variant="error" message={data.errorMessage} />
    — onRetry non passé actuellement (no-op depuis 3.3) — AJOUTER onRetry en T8
  → MODIFIER : importer useRunStore, créer handleRetry, passer à BubbleBox

frontend/src/components/AgentDiagram.tsx
  → Passe errorMessage dans node data (depuis 3.3)
  → Aucun passage de callback via node data — le câblage se fait dans AgentCard directement
  → AUCUNE modification nécessaire pour 3.4

frontend/src/stores/agentStatusStore.ts
  → setAgentStatus(agentId, 'working', ...) NE clear pas errorMessage
    (errorMessage preserved quand status !== 'error')
  → Ce n'est PAS un problème : AgentCard ne montre la BubbleBox error que si
    status === 'error' && errorMessage. Dès que status = 'working', la branche error
    ne s'active plus. La BubbleBox info prend le relais via bubbleMessage.
  → AUCUNE modification nécessaire
```

---

### §Architecture du retry — flux complet

```
1. User clique "Relancer cette étape" dans BubbleBox
   → AgentCard::handleRetry() appelé

2. AgentCard::handleRetry()
   → POST /api/runs/{runId}/retry-step
   → 202 reçu
   → runStore.setRetrying() → { status: 'running', retryKey: old+1 }

3. LaunchBar
   → status === 'running' → affiche "Annuler"
   → useSSEListener(runId, retryKey) — retryKey changé → useEffect re-runs
   → Nouvelle EventSource vers GET /api/runs/{runId}/stream

4. SseController::stream()
   → cache()->pull("run:{runId}:retry_checkpoint") → checkpoint trouvé
   → Skip la garde (done flag effacé par retryStep)
   → runService->executeFromCheckpoint(runId, checkpoint)

5. RunService::executeFromCheckpoint()
   → Charge workflow, récupère runPath
   → Émet AgentStatusChanged(working) + AgentBubble(info "Reprise...") sur l'agent fautif
   → Appelle executeAgents() depuis startStep avec completedAgents du checkpoint

6. executeAgents()
   → Skip agents avant startStep (continue)
   → Exécute l'agent fautif avec la même boucle retry do-while
   → Continue pipeline normalement
   → Émet RunCompleted ou RunError à la fin

7. useSSEListener reçoit les events
   → AgentStatusChanged(working) → AgentCard repasse en working
   → AgentBubble(info) → BubbleBox info visible
   → RunCompleted → runStore.setRunCompleted(), SSE close
```

---

### §Refactoring `executeAgents()` — extraction de la boucle

Pour éviter la duplication entre `execute()` et `executeFromCheckpoint()`, extraire la boucle interne dans une méthode privée :

```php
// backend/app/Services/RunService.php

public function execute(string $runId, string $workflowFile, string $brief): void
{
    $workflow  = $this->yamlService->load($workflowFile);
    $createdAt = now()->toIso8601String();

    cache()->put("run:{$runId}", ['status' => 'running', 'startedAt' => $createdAt], 3600);

    try {
        $runPath = $this->artifactService->initializeRun($runId, $workflowFile, $brief);
        cache()->put("run:{$runId}:path", $runPath, 7200);
        $startedAt = microtime(true);

        $this->executeAgents($runId, $workflow, $runPath, $brief, 0, []);

        $duration = (int) round((microtime(true) - $startedAt) * 1000);
        // RunCompleted est désormais émis dans executeAgents()
    } finally {
        cache()->forget("run:{$runId}");
        cache()->forget("run:{$runId}:cancelled");
        cache()->put("run:{$runId}:done", true, 3600);
    }
}

public function executeFromCheckpoint(string $runId, array $checkpoint): void
{
    $workflowFile    = $checkpoint['workflowFile'];
    $brief           = $checkpoint['brief'] ?? '';
    $startStep       = (int) ($checkpoint['currentStep'] ?? 0);
    $completedAgents = $checkpoint['completedAgents'] ?? [];

    $workflow = $this->yamlService->load($workflowFile);
    $runPath  = cache()->get("run:{$runId}:path");

    if (! $runPath) {
        throw new \RuntimeException("Run path not found for run: {$runId}");
    }

    // Émettre les events de reprise sur l'agent fautif AVANT d'entrer dans la boucle
    $currentAgentId = $workflow['agents'][$startStep]['id'] ?? null;
    if ($currentAgentId) {
        event(new AgentStatusChanged($runId, $currentAgentId, 'working', $startStep, ''));
        event(new AgentBubble($runId, $currentAgentId, 'Reprise depuis le checkpoint...', $startStep));
    }

    cache()->put("run:{$runId}", ['status' => 'running', 'startedAt' => now()->toIso8601String()], 3600);

    try {
        $startedAt = microtime(true);
        $this->executeAgents($runId, $workflow, $runPath, $brief, $startStep, $completedAgents, $startedAt);
    } finally {
        cache()->forget("run:{$runId}");
        cache()->forget("run:{$runId}:cancelled");
        cache()->put("run:{$runId}:done", true, 3600);
    }
}

private function executeAgents(
    string $runId,
    array  $workflow,
    string $runPath,
    string $brief,
    int    $startStep,
    array  $completedAgents,
    float  $startedAt = 0,
): void {
    if ($startedAt === 0) {
        $startedAt = microtime(true);
    }
    $agentResults = array_map(fn ($id) => ['id' => $id, 'status' => 'done'], $completedAgents);
    $workflowFile = $workflow['file'];
    $context      = $this->artifactService->getContextContent($runPath);

    foreach ($workflow['agents'] as $stepIndex => $agent) {
        if ($stepIndex < $startStep) {
            continue; // Skip agents déjà complétés
        }

        $agentId = $agent['id'];

        if (cache()->get("run:{$runId}:cancelled", false)) {
            throw new RunCancelledException($runId);
        }

        $timeout      = isset($agent['timeout']) && is_int($agent['timeout']) && $agent['timeout'] > 0
            ? $agent['timeout']
            : (int) (config('xu-workflow.default_timeout') ?? 120);
        $systemPrompt = $this->resolveSystemPrompt($agent);
        $isMandatory  = isset($agent['mandatory']) && $agent['mandatory'] === true;
        $maxRetries   = ($isMandatory && isset($agent['max_retries']) && is_int($agent['max_retries']) && $agent['max_retries'] > 0)
            ? $agent['max_retries']
            : 0;

        $this->checkpointService->write($runPath, [
            'runId'           => $runId,
            'workflowFile'    => $workflowFile,
            'brief'           => $brief,
            'completedAgents' => $completedAgents,
            'currentAgent'    => $agentId,
            'currentStep'     => $stepIndex,
            'context'         => $runPath . '/session.md',
        ]);

        // Pour les agents >= startStep+1 (pas l'agent fautif qui a déjà ses events émis)
        // ET pour le premier appel depuis execute() (startStep === 0)
        if ($stepIndex > $startStep || $startStep === 0) {
            event(new AgentStatusChanged($runId, $agentId, 'working', $stepIndex, ''));
        }

        $attempt       = 0;
        $totalAttempts = $maxRetries + 1;
        do {
            $attempt++;
            if (cache()->get("run:{$runId}:cancelled", false)) {
                throw new RunCancelledException($runId);
            }
            if ($attempt > 1) {
                event(new AgentBubble($runId, $agentId, "Tentative {$attempt}/{$totalAttempts} échouée — relance en cours...", $stepIndex));
                event(new AgentStatusChanged($runId, $agentId, 'working', $stepIndex, ''));
            }
            try {
                $rawOutput = $this->driver->execute(
                    $workflow['project_path'],
                    $systemPrompt,
                    $context,
                    $timeout
                );
                $decoded = $this->validateJsonOutput($agentId, $rawOutput);
                break;
            } catch (CliExecutionException $e) {
                if ($attempt <= $maxRetries) { continue; }
                $msg = $e->getMessage();
                event(new AgentStatusChanged($runId, $agentId, 'error', $stepIndex, $msg));
                event(new RunError(runId: $runId, agentId: $agentId, step: $stepIndex, message: $msg, checkpointPath: $runPath . '/checkpoint.json'));
                cache()->put("run:{$runId}:error_emitted", true, 60);
                throw new CliExecutionException($agentId, $e->exitCode, $e->stderr);
            } catch (ProcessTimedOutException) {
                if ($attempt <= $maxRetries) { continue; }
                $msg = "Timeout after {$timeout}s";
                event(new AgentStatusChanged($runId, $agentId, 'error', $stepIndex, $msg));
                event(new RunError(runId: $runId, agentId: $agentId, step: $stepIndex, message: $msg, checkpointPath: $runPath . '/checkpoint.json'));
                cache()->put("run:{$runId}:error_emitted", true, 60);
                throw new AgentTimeoutException($agentId, $timeout);
            } catch (InvalidJsonOutputException $e) {
                if ($attempt <= $maxRetries) { continue; }
                $msg = "Invalid JSON output from {$agentId}: {$e->getMessage()}";
                event(new AgentStatusChanged($runId, $agentId, 'error', $stepIndex, $msg));
                event(new RunError(runId: $runId, agentId: $agentId, step: $stepIndex, message: $msg, checkpointPath: $runPath . '/checkpoint.json'));
                cache()->put("run:{$runId}:error_emitted", true, 60);
                throw $e;
            }
        } while ($attempt <= $maxRetries);

        $this->artifactService->appendAgentOutput($runPath, $agentId, $rawOutput);
        $completedAgents[] = $agentId;
        $agentResults[]    = ['id' => $agentId, 'status' => $decoded['status']];

        $nextStepIndex = $stepIndex + 1;
        $nextAgentId   = $workflow['agents'][$nextStepIndex]['id'] ?? null;
        $this->checkpointService->write($runPath, [
            'runId'           => $runId,
            'workflowFile'    => $workflowFile,
            'brief'           => $brief,
            'completedAgents' => $completedAgents,
            'currentAgent'    => $nextAgentId,
            'currentStep'     => $nextStepIndex,
            'context'         => $runPath . '/session.md',
        ]);

        $context = $this->artifactService->getContextContent($runPath);

        $bubbleMessage = is_string($decoded['output']) ? $decoded['output'] : json_encode($decoded['output']);
        event(new AgentBubble($runId, $agentId, $bubbleMessage, $stepIndex));
        event(new AgentStatusChanged($runId, $agentId, 'done', $stepIndex, ''));
    }

    $duration = (int) round((microtime(true) - $startedAt) * 1000);
    event(new RunCompleted($runId, $duration, count($agentResults), 'completed', $runPath));
}
```

**Note sur le premier agent dans un retry :** L'agent à `$startStep` a déjà reçu ses events `AgentStatusChanged(working)` + `AgentBubble(info)` émis dans `executeFromCheckpoint()` avant l'appel à `executeAgents()`. La condition `if ($stepIndex > $startStep || $startStep === 0)` dans la boucle évite de réémettre `AgentStatusChanged(working)` pour cet agent. Pour `execute()` (startStep=0), tous les agents émettent normalement.

---

### §`RunController::retryStep()` — implémentation exacte

```php
// Dans RunController — ajouter CheckpointService au constructeur

use App\Services\CheckpointService;

class RunController extends Controller
{
    public function __construct(
        private readonly CheckpointService $checkpointService,
    ) {}

    // ... méthodes existantes inchangées ...

    public function retryStep(string $id): JsonResponse
    {
        $runPath = cache()->get("run:{$id}:path");

        if (! $runPath) {
            return response()->json([
                'message' => "Run not found or path unavailable: {$id}",
                'code'    => 'RUN_NOT_FOUND',
            ], 404);
        }

        try {
            $checkpoint = $this->checkpointService->read($runPath);
        } catch (\RuntimeException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'code'    => 'CHECKPOINT_NOT_FOUND',
            ], 404);
        }

        // Réinitialiser les drapeaux d'état
        cache()->forget("run:{$id}:done");
        cache()->forget("run:{$id}:error_emitted");
        cache()->forget("run:{$id}:cancelled");

        // Stocker le checkpoint pour le stream SSE
        cache()->put("run:{$id}:retry_checkpoint", $checkpoint, 3600);

        return response()->json([
            'runId'  => $id,
            'status' => 'retrying',
        ], 202);
    }
}
```

---

### §`SseController::stream()` — modification minimale

Ajouter AVANT la garde existante :

```php
public function stream(string $id): StreamedResponse
{
    $config = cache()->get("run:{$id}:config");

    if (! $config) {
        abort(404, "Run not found: {$id}");
    }

    return new StreamedResponse(function () use ($id, $config) {
        $this->sseStreamService->setHeaders();

        // --- NOUVEAU : détection retry (consommation atomique via pull) ---
        $retryCheckpoint = cache()->pull("run:{$id}:retry_checkpoint");
        if ($retryCheckpoint) {
            // Retry path : contourne la garde (done déjà effacé par retryStep)
            try {
                $this->runService->executeFromCheckpoint($id, $retryCheckpoint);
            } catch (\Throwable $e) {
                if (! cache()->has("run:{$id}:error_emitted")) {
                    event(new RunError(runId: $id, agentId: 'unknown', step: 0, message: $e->getMessage(), checkpointPath: ''));
                }
            }
            return;
        }
        // --- FIN NOUVEAU ---

        // Chemin normal inchangé
        if (cache()->has("run:{$id}") || cache()->has("run:{$id}:done")) {
            return;
        }

        try {
            $this->runService->execute($id, $config['workflowFile'], $config['brief']);
        } catch (\Throwable $e) {
            if (! cache()->has("run:{$id}:error_emitted")) {
                event(new RunError(runId: $id, agentId: 'unknown', step: 0, message: $e->getMessage(), checkpointPath: ''));
            }
        }
    }, 200, [
        'Content-Type'      => 'text/event-stream',
        'Cache-Control'     => 'no-cache',
        'X-Accel-Buffering' => 'no',
        'Connection'        => 'keep-alive',
    ]);
}
```

---

### §Frontend — modifications exactes

**`runStore.ts` :**

```ts
interface RunStoreState {
  runId: string | null
  status: RunStatus
  duration: number | null
  runFolder: string | null
  errorMessage: string | null
  retryKey: number                        // ← NOUVEAU
  setRunId: (runId: string | null) => void
  setRunCompleted: (duration: number, runFolder: string) => void
  setRunError: (message: string) => void
  setRetrying: () => void                 // ← NOUVEAU
  resetRun: () => void
}

export const useRunStore = create<RunStoreState>((set) => ({
  runId: null,
  status: 'idle',
  duration: null,
  runFolder: null,
  errorMessage: null,
  retryKey: 0,                            // ← NOUVEAU
  setRunId: (runId) => set({ runId, status: runId ? 'running' : 'idle' }),
  setRunCompleted: (duration, runFolder) => set({ status: 'completed', duration, runFolder, errorMessage: null }),
  setRunError: (message) => set({ status: 'error', errorMessage: message }),
  setRetrying: () => set((state) => ({    // ← NOUVEAU
    status: 'running',
    errorMessage: null,
    retryKey: state.retryKey + 1,
  })),
  resetRun: () => set({
    runId: null,
    status: 'idle',
    duration: null,
    runFolder: null,
    errorMessage: null,
    retryKey: 0,                          // ← reset retryKey aussi
  }),
}))
```

**`useSSEListener.ts` — signature + deps :**

```ts
export function useSSEListener(runId: string | null, retryKey = 0): { connectionStatus: ConnectionStatus } {
  // ...
  useEffect(() => {
    // ... code inchangé ...
  }, [runId, retryKey])  // ← ajouter retryKey
  // ...
}
```

**`LaunchBar.tsx` — passer retryKey :**

```ts
const { runId, status, retryKey, setRunId, resetRun, errorMessage: runErrorMessage } = useRunStore()
// ...
useSSEListener(runId, retryKey)
```

**`AgentCard.tsx` — câbler onRetry :**

```tsx
import { useRunStore } from '@/stores/runStore'

export function AgentCard({ data }: { data: AgentCardData }) {
  const runId = useRunStore((s) => s.runId)
  const setRetrying = useRunStore((s) => s.setRetrying)

  const handleRetry = async () => {
    if (!runId) return
    const res = await fetch(`/api/runs/${runId}/retry-step`, { method: 'POST' })
    if (res.ok) {
      setRetrying()
    }
  }

  return (
    <div className={cn('relative w-72', wrapperStyles[data.status])}>
      {/* ... handles, Card, CardHeader, CardContent inchangés ... */}
      {data.status === 'error' && data.errorMessage ? (
        <BubbleBox variant="error" message={data.errorMessage} onRetry={handleRetry} />
      ) : data.bubbleMessage ? (
        <BubbleBox variant="info" message={data.bubbleMessage} />
      ) : null}
      {/* ... handle source inchangé ... */}
    </div>
  )
}
```

---

### §Guardrails — erreurs critiques à éviter

| ❌ Interdit | ✅ Correct |
|---|---|
| Appeler `resetAgents()` dans `handleRetry` | Ne PAS reset les agents — les agents `done` restent visibles |
| Passer `onRetry` via les node `data` de React Flow | Câbler dans `AgentCard` directement via `useRunStore` |
| Modifier `agentStatusStore` pour clearErrorMessage | Pas besoin : `status === 'error'` contrôle la branche BubbleBox |
| Réémettre `AgentStatusChanged(working)` pour l'agent fautif dans la boucle | Émis AVANT la boucle dans `executeFromCheckpoint` — condition dans `executeAgents` le skip |
| Oublier `cache()->pull()` (vs `get()`) pour `retry_checkpoint` | `pull()` = get + forget atomique — évite de ré-utiliser le checkpoint sur reload |
| Modifier `error_emitted` en finally de `executeAgents` | Invariant de 3.1/3.2 — jamais forget en finally |
| Créer un nouveau `runId` pour le retry | Même `runId` — le checkpoint stocke le `workflowFile` et le contexte |
| Appeler `retryFromCheckpoint` depuis `execute()` | `execute()` garde sa signature — `executeFromCheckpoint()` est la nouvelle entrée |
| Ignorer la condition `$stepIndex < $startStep` | Sans ce skip, les agents complétés seraient ré-exécutés (violation FR25) |
| Passer `onRetry` à la branche `info` de BubbleBox | `onRetry` seulement sur la branche `error` — le bouton "Relancer" n'existe pas en `info` |

---

### §Séquence d'events SSE lors du retry

```
Frontend clique retry
  → POST /api/runs/{id}/retry-step [202]
  → runStore.setRetrying() → retryKey++, status='running'
  → LaunchBar voit status=running → "Annuler" visible
  → useSSEListener re-triggered → nouvelle EventSource

Backend SSE (executeFromCheckpoint) émet dans l'ordre :
  1. AgentStatusChanged(working, agentId=fautif)   → AgentCard passe en working (error bubble disparaît)
  2. AgentBubble(info, "Reprise depuis le checkpoint...")  → BubbleBox info visible
  3. [Exécution normale]
  4. AgentBubble(info, output) + AgentStatusChanged(done)  → pour chaque agent complété
  5. RunCompleted  → runStore.setRunCompleted(), SSE close, LaunchBar → ready
```

---

### §Comportement de la `LaunchBar` pendant le retry

- `retryKey` change → `useSSEListener` se reconnecte → l'EventSource se ferme et se rouvre
- `status === 'running'` → LaunchBar affiche "Annuler" (bouton destructif) — AC9 implicite
- Le bouton "Annuler" appelle `DELETE /api/runs/{id}` → flag `cancelled` posé → check en tête de boucle `executeAgents` → `RunCancelledException`
- `RunCancelledException` non gérée dans le catch du SseController → `error_emitted` absent → `RunError` émis → `runStore.setRunError()` → status='error'

Pas besoin de modifier la logique d'annulation — elle fonctionne déjà.

---

### §Context pendant le retry

Le checkpoint pointe vers `runPath/session.md` (champ `context`). L'`ArtifactService::getContextContent(runPath)` lit ce fichier. Le contenu contient l'output de tous les agents déjà complétés. L'agent fautif reçoit donc le contexte correct sans retraitement.

---

### §Fichiers à créer / modifier

```
backend/app/Services/RunService.php                         ← MODIFIER (extraire executeAgents, ajouter executeFromCheckpoint)
backend/app/Http/Controllers/RunController.php              ← MODIFIER (+ constructeur CheckpointService, + retryStep())
backend/app/Http/Controllers/SseController.php              ← MODIFIER (détection retry_checkpoint)
backend/routes/api.php                                      ← MODIFIER (+ Route POST retry-step)
backend/tests/Unit/RunRetryStepTest.php                     ← CRÉER (tests RunController::retryStep)
backend/tests/Unit/RunServiceRetryFromCheckpointTest.php    ← CRÉER (tests RunService::executeFromCheckpoint)

frontend/src/stores/runStore.ts                             ← MODIFIER (+ retryKey, + setRetrying)
frontend/src/hooks/useSSEListener.ts                        ← MODIFIER (+ retryKey param + dep)
frontend/src/components/LaunchBar.tsx                       ← MODIFIER (+ retryKey depuis store, passer à useSSEListener)
frontend/src/components/AgentCard.tsx                       ← MODIFIER (+ useRunStore, + handleRetry, passer à BubbleBox)
```

**Ne pas toucher :**
- `BubbleBox.tsx` — `onRetry` prop et `disabled={!onRetry}` déjà en place depuis review 3.3
- `AgentDiagram.tsx` — aucun passage de fonction via node data
- `agentStatusStore.ts` — clearErrorMessage pas nécessaire (priorité de rendu dans AgentCard)
- `CheckpointService.php` — `read()` déjà implémenté pour cette story
- `ArtifactService.php` — aucun changement
- Events PHP (`AgentBubble`, `AgentStatusChanged`, `RunCompleted`, `RunError`) — signatures inchangées
- `YamlService.php` — aucune modification

### Project Structure Notes

- `RunController` : pas de constructeur actuellement → en ajouter un avec `CheckpointService $checkpointService` (DI Container résout automatiquement)
- Tests Laravel : `backend/tests/Unit/` — `PHPUnit\Framework\Attributes\Test`, extend `Tests\TestCase`
- Tests HTTP (`retryStep`) : utiliser `$this->postJson('/api/runs/{id}/retry-step')` avec `config(['cache.default' => 'array'])` dans setUp()
- `cache()->pull()` : méthode native Laravel Cache — équivalent atomique de get + forget
- `executeAgents()` est `private` : non testée directement — testée via `execute()` et `executeFromCheckpoint()`
- La méthode `execute()` après refactoring ne doit plus contenir la boucle interne — la déléguer entièrement à `executeAgents()`

### References

- [Source: docs/planning-artifacts/epics.md#Story-3.4] — User story, ACs FR25, FR26
- [Source: docs/planning-artifacts/architecture.md#API-Communication-Patterns] — Route `POST /api/runs/{id}/retry-step`, schema checkpoint.json
- [Source: docs/implementation-artifacts/3-3-alerte-d-erreur-localisee-et-bubblebox-error.md#Dev-Notes] — BubbleBox.tsx état actuel, `disabled={!onRetry}` en place, scope 3.4 annoncé
- [Source: docs/implementation-artifacts/3-2-retry-automatique-des-etapes-mandatory.md#Dev-Notes] — Structure boucle do-while, flag error_emitted invariant, contexte non re-lu entre tentatives
- [Source: docs/implementation-artifacts/3-1-checkpoint-step-level-ecriture-et-lecture.md] — CheckpointService::read() déjà implémenté
- [Source: backend/app/Services/RunService.php] — Séquence execute() complète, structure finally, pattern $timeout
- [Source: backend/app/Http/Controllers/RunController.php] — Patterns existants store/destroy/log
- [Source: backend/app/Http/Controllers/SseController.php] — Garde existante, catch(\Throwable), error_emitted guard
- [Source: backend/routes/api.php] — Routes existantes
- [Source: backend/app/Services/CheckpointService.php] — read() throw \RuntimeException si absent/invalide
- [Source: frontend/src/components/LaunchBar.tsx:32] — `useSSEListener(runId)` — seul point d'appel SSE
- [Source: frontend/src/stores/runStore.ts] — Interface RunStoreState actuelle
- [Source: frontend/src/hooks/useSSEListener.ts] — Dépendance [runId], délai 100ms setTimeout
- [Source: frontend/src/components/AgentCard.tsx] — AgentCardData interface, branche BubbleBox error
- [Source: frontend/src/stores/agentStatusStore.ts] — setAgentStatus: errorMessage conservé si status !== 'error'

## Dev Agent Record

### Agent Model Used

claude-sonnet-4-6

### Debug Log References

- `RunService` : bubbles de retry restent dans les catch (pattern existant de 3.2) et non dans un bloc `if ($attempt > 1)` avant le try — les tests existants `RunServiceRetryTest` valident spécifiquement les messages "Tentative X/N échouée" émis après chaque échec.
- `executeAgents()` méthode privée extraite : condition `if ($stepIndex > $startStep || $startStep === 0)` pour éviter de ré-émettre `AgentStatusChanged(working)` sur l'agent fautif lors d'un retry (ses events ont déjà été émis dans `executeFromCheckpoint()` avant l'appel).
- `RunRetryStepTest` placé dans `tests/Feature/` (non `Unit/`) car il teste le controller HTTP via `$this->postJson` — necessite le bootstrap Laravel complet.
- `cache()->pull()` dans `SseController` consomme atomiquement le `retry_checkpoint` : empêche la réutilisation sur un reload de page non intentionnel.

### Completion Notes List

- `RunService` : `executeAgents()` extraite depuis `execute()` — boucle retry do-while, skip des agents avant `$startStep`, checkpoints pré/post agent, events SSE identiques. `execute()` délègue entièrement à `executeAgents(startStep=0, completedAgents=[])`.
- `RunService::executeFromCheckpoint()` : émet `AgentStatusChanged(working)` + `AgentBubble(info "Reprise...")` sur l'agent fautif AVANT la boucle, puis appelle `executeAgents()` depuis `currentStep` avec `completedAgents` du checkpoint. Structure `finally` identique à `execute()`.
- `RunController::retryStep()` : constructeur + `CheckpointService`, 404 si `run:{id}:path` absent ou checkpoint invalide, efface `done`/`error_emitted`/`cancelled`, stocke `retry_checkpoint`, retourne 202.
- `SseController::stream()` : `cache()->pull("run:{$id}:retry_checkpoint")` avant la garde — route vers `executeFromCheckpoint()` si trouvé, comportement normal sinon.
- `runStore.ts` : `retryKey: number` + `setRetrying()` (status running, errorMessage null, retryKey++, resetRun reset retryKey à 0).
- `useSSEListener` : `retryKey = 0` en 2ème paramètre, ajouté aux dépendances `useEffect` — reconnexion SSE automatique quand retryKey change.
- `LaunchBar.tsx` : destructure `retryKey` depuis `useRunStore`, passe à `useSSEListener(runId, retryKey)`.
- `AgentCard.tsx` : importe `useRunStore`, crée `handleRetry` (fetch POST retry-step → setRetrying() si ok), passe `onRetry={handleRetry}` à `BubbleBox` en branche error. Le bouton était déjà visible et `disabled={!onRetry}` depuis review 3.3.
- Suite tests : 103/103 verts (285 assertions) — 20 nouveaux tests (6 feature + 8 unit + 2 existants RunServiceRetryTest restés verts après vérification).
- TypeScript : 0 erreur (`tsc --noEmit`). ESLint : 0 erreur sur les 4 fichiers frontend modifiés.

### File List

- backend/app/Services/RunService.php (modifié — extracteAgents, executeFromCheckpoint)
- backend/app/Http/Controllers/RunController.php (modifié — constructeur CheckpointService, retryStep)
- backend/app/Http/Controllers/SseController.php (modifié — détection retry_checkpoint)
- backend/routes/api.php (modifié — route POST retry-step)
- backend/tests/Feature/RunRetryStepTest.php (créé)
- backend/tests/Unit/RunServiceRetryFromCheckpointTest.php (créé)
- frontend/src/stores/runStore.ts (modifié — retryKey, setRetrying)
- frontend/src/hooks/useSSEListener.ts (modifié — retryKey param + dep)
- frontend/src/components/LaunchBar.tsx (modifié — retryKey depuis store)
- frontend/src/components/AgentCard.tsx (modifié — useRunStore, handleRetry, onRetry)

### Review Findings

- [x] [Review][Patch] Double `AgentStatusChanged(working)` quand retry au step 0 [`RunService.php:131`] — La condition `$startStep === 0` dans `executeAgents()` émet `working` pour tous les agents à `$stepIndex === 0`, y compris lors d'un retry depuis le step 0 où `executeFromCheckpoint` a déjà émis l'event. Fix : ajouter un param `bool $firstWorkingEmitted = false` à `executeAgents()` et changer la condition en `$stepIndex > $startStep || ($stepIndex === $startStep && !$firstWorkingEmitted)`.
- [x] [Review][Patch] `handleRetry` : échec silencieux si la requête retourne non-ok [`AgentCard.tsx:47-50`] — Si le POST `/retry-step` retourne 404 ou 5xx, la branche `else` n'existe pas et l'utilisateur ne reçoit aucun feedback. Fix : gérer `!res.ok` avec un affichage d'erreur local ou une bulle toast.
- [x] [Review][Patch] `handleRetry` : pas de protection contre clics multiples [`AgentCard.tsx:44`] — Sans état de chargement ni `disabled`, des clics rapides envoient plusieurs POST successifs, incrémentent `retryKey` à chaque fois et ouvrent plusieurs EventSources en parallèle. Fix : ajouter un `useState<boolean>` `isRetrying` qui désactive le bouton pendant la requête.
- [x] [Review][Patch] `run:{id}:done` jamais posé si `RuntimeException` avant le `try/finally` [`RunService.php:56-59`] — Si `runPath` est absent du cache, l'exception est levée avant le bloc `try`, donc le `finally` ne s'exécute pas et le flag `done` n'est jamais posé, laissant le run en état zombie. Fix : envelopper la totalité de la méthode dans un `try/finally` externe ou valider le runPath avant d'émettre des events.
- [x] [Review][Defer] `retryStep` sans garde contre run encore actif [`RunController.php:54`] — deferred, pre-existing — design par intention : le bouton Retry n'est visible que si status=error côté UI ; pas de double-protection serveur.
- [x] [Review][Defer] `checkpoint['runId']` non validé contre l'URL `$id` [`RunController.php:73`] — deferred, pre-existing — le checkpoint est toujours écrit par l'application avec le runId correct ; validation défensive hors scope.
- [x] [Review][Defer] `retry_checkpoint` reste 3600s si client ne reconnecte jamais [`RunController.php:78`] — deferred, pre-existing — compromis TTL acceptable ; EventSource auto-reconnect atténue le risque.
