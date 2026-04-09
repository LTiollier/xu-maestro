# Story 3.1 : Checkpoint step-level — écriture et lecture

Status: done

## Story

As a développeur,
I want que le moteur écrive un checkpoint après chaque étape complétée et sache le relire,
so that le système peut reprendre un run depuis n'importe quel point sans recalcul.

## Acceptance Criteria

1. **Given** un run en cours avec un agent qui vient de compléter une étape — **When** l'étape est marquée complétée — **Then** `CheckpointService` écrit `checkpoint.json` sur disque **avant** d'émettre l'événement `done` (NFR6) — **And** le `checkpoint.json` contient : `{ runId, workflowFile, brief, completedAgents[], currentAgent, currentStep, context }` — **And** `completedAgents` inclut l'agent qui vient de compléter

2. **Given** un `checkpoint.json` valide sur disque — **When** `CheckpointService::read($runPath)` est appelé — **Then** la méthode retourne un tableau PHP correspondant exactement au contenu du checkpoint — **And** aucune exception si le fichier est bien formé

3. **Given** un crash Laravel entre deux étapes — **When** le système redémarre — **Then** le dernier `checkpoint.json` intact correspond à l'état après la dernière étape complétée — **And** `completedAgents` ne contient que les agents effectivement terminés

4. **Given** une erreur d'agent (JSON invalide, timeout, CLI error) — **When** l'erreur se produit — **Then** l'événement `run.error` est émis depuis `RunService` avec : `agentId` réel, `step` réel (non zéro si pas premier agent), `checkpointPath` = chemin vers `checkpoint.json` — **And** le `step` dans tous les événements SSE correspond à l'index réel de l'agent dans la liste (0-based)

## Tasks / Subtasks

- [x] **T1 — Créer `CheckpointService`** (AC 1, 2, 3)
  - [x] Créer `backend/app/Services/CheckpointService.php`
  - [x] Méthode `write(string $runPath, array $data): void` — encode JSON + sanitise credentials + `File::put()`
  - [x] Méthode `read(string $runPath): array` — lit `checkpoint.json`, parse JSON, retourne array
  - [x] `read()` lève `\RuntimeException` si fichier absent ou JSON invalide

- [x] **T2 — Modifier `RunService`** (AC 1, 3, 4)
  - [x] Injecter `CheckpointService` dans le constructeur
  - [x] Remplacer `$this->artifactService->writeCheckpoint(...)` par `$this->checkpointService->write(...)`
  - [x] Utiliser `foreach ($workflow['agents'] as $stepIndex => $agent)` — remplacer `as $agent`
  - [x] Passer `$stepIndex` dans tous les events SSE (remplacer `0` hardcodé)
  - [x] **AJOUTER** un deuxième `$this->checkpointService->write(...)` APRÈS `$completedAgents[] = $agentId` et AVANT `event(AgentBubble)` / `event(done)` — avec `completedAgents` incluant l'agent courant
  - [x] Émettre `event(new RunError(...))` depuis les catch internes avec `agentId` réel, `$stepIndex`, et `$runPath . '/checkpoint.json'`
  - [x] Patch A5 rétro Épic 2 : remplacer `(int) config('xu-workflow.default_timeout', 120)` par `(int) (config('xu-workflow.default_timeout') ?? 120)`

- [x] **T3 — Modifier `SseController`** (AC 4)
  - [x] Guard double RunError via flag cache `run:{id}:error_emitted` — n'émet que si RunService n'a pas déjà émis

- [x] **T4 — Tests unitaires `CheckpointServiceTest`** (AC 1, 2, 3)
  - [x] Créer `backend/tests/Unit/CheckpointServiceTest.php`
  - [x] Test : `write` crée un `checkpoint.json` avec le bon contenu JSON
  - [x] Test : `read` retourne l'array exact du checkpoint écrit
  - [x] Test : `write` puis crash → `read` retourne le dernier état cohérent (simulated via écriture directe)
  - [x] Test : `read` lève `RuntimeException` si fichier absent
  - [x] Test : `write` sanitise les credentials (pattern existant dans `ArtifactServiceTest`)

- [x] **T5 — Vérification** (AC 1–4)
  - [x] `php artisan test` : 74/74 tests verts (62 existants + 12 nouveaux), 0 régression

### Review Findings (2026-04-09)

- [ ] [Review][Decision] Valeur sémantique de `currentStep` dans le checkpoint post-completion du dernier agent — Pour le dernier agent, le post-completion checkpoint écrit `currentStep: count(agents)` (index hors-tableau) et `currentAgent: null`. Deux interprétations possibles : (A) conserver tel quel — `currentAgent: null` + `currentStep: N` signifie "run terminé, prochain = none" ; (B) écrire `currentStep: $stepIndex` (index de l'agent qui vient de compléter) pour cohérence avec un modèle "étape courante". La Story 3.4 (retry) devra traiter ce sentinel — décision requise avant implémentation.
- [ ] [Review][Patch] Double-émission RunError : `finally` efface le flag `error_emitted` AVANT que `SseController` le lise [RunService.php:~153]
- [ ] [Review][Patch] Absence de test vérifiant l'ordre checkpoint-before-done — aucune assertion dans RunServiceTest ne garantit que `checkpointService->write()` est appelé avant `event(AgentStatusChanged 'done')` [RunServiceTest.php]
- [x] [Review][Defer] `sanitizeEnvCredentials` dupliquée dans `ArtifactService` et `CheckpointService` [CheckpointService.php:47, ArtifactService.php:85] — deferred, refactor futur
- [x] [Review][Defer] `checkpointService->write()` failure (disk full, permissions) non gérée — même pattern que `ArtifactService` pre-existing [CheckpointService.php:write()] — deferred, pre-existing
- [x] [Review][Defer] `RunCancelledException` laisse un checkpoint pré-agent pointant vers un agent potentiellement déjà complété — risque Story 3.4 retry [RunService.php:~47] — deferred, scope Story 3.4
- [x] [Review][Defer] Regex `sanitizeEnvCredentials` ne couvre pas toutes les conventions de nommage (ex: `GITHUB_PAT`, `STRIPE_SK`) — pre-existing, partagé avec `ArtifactService` — deferred, pre-existing
- [x] [Review][Defer] `resolveSystemPrompt` retourne silencieusement `''` si le fichier system prompt est absent — pre-existing [RunService.php:resolveSystemPrompt()] — deferred, pre-existing

---

## Dev Notes

### §ÉTAT ACTUEL — Ce qui existe déjà (ne pas réinventer)

```
backend/app/Services/ArtifactService.php
  → writeCheckpoint(string $runPath, array $data): void  ← PUBLIC, utilisé dans initializeRun()
  → initializeRun() écrit UN checkpoint initial (completedAgents=[], currentAgent=null, currentStep=0)
  → PAS de readCheckpoint()

backend/app/Services/RunService.php
  → writeCheckpoint() appelé UNE FOIS par agent, AVANT le spawn CLI
  → completedAgents dans ce checkpoint = agents DÉJÀ complétés (n'inclut PAS l'agent courant)
  → Pas de 2ème écriture après completion → VIOLATION NFR6
  → step: 0 hardcodé dans tous les event() → 🔴 BLOQUANT (retro Épic 2, A2)
  → RunError émis via SseController catch-all, pas depuis RunService → agentId:'unknown', step:0, checkpointPath:''

backend/app/Http/Controllers/SseController.php ligne 34-42
  → catch (\Throwable $e) → event(new RunError(runId:$id, agentId:'unknown', step:0, message:$e->getMessage(), checkpointPath:''))
  → DOIT être corrigé pour ne pas double-émettre si RunService émet déjà RunError
```

**Schéma checkpoint.json (architecture doc) — INCHANGÉ :**
```json
{
  "runId": "uuid",
  "workflowFile": "feature-dev.yaml",
  "brief": "...",
  "completedAgents": ["pm", "laravel-dev"],
  "currentAgent": "qa",
  "currentStep": 2,
  "context": "runs/2026-04-02-1430/session.md"
}
```

---

### §CheckpointService — structure exacte

```php
<?php
// backend/app/Services/CheckpointService.php
namespace App\Services;

use Illuminate\Support\Facades\File;

class CheckpointService
{
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

    // Même logique que ArtifactService::sanitizeEnvCredentials (NFR12)
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
```

---

### §RunService — séquence modifiée complète (diff annoté)

```php
// AVANT: public function __construct(
//     private readonly DriverInterface $driver,
//     private readonly YamlService $yamlService,
//     private readonly ArtifactService $artifactService,
// ) {}

// APRÈS:
public function __construct(
    private readonly DriverInterface $driver,
    private readonly YamlService $yamlService,
    private readonly ArtifactService $artifactService,
    private readonly CheckpointService $checkpointService,  // ← AJOUTER
) {}

// ... dans execute() :

// AVANT: foreach ($workflow['agents'] as $agent) {
// APRÈS:
foreach ($workflow['agents'] as $stepIndex => $agent) {  // ← AJOUTER $stepIndex

    $agentId = $agent['id'];

    // ... cancelled check ...

    // Checkpoint PRÉ-AGENT (état "sur le point de lancer $agentId")
    // AVANT: $this->artifactService->writeCheckpoint(...)
    // APRÈS:
    $this->checkpointService->write($runPath, [
        'runId'           => $runId,
        'workflowFile'    => $workflowFile,
        'brief'           => $brief,
        'completedAgents' => $completedAgents,
        'currentAgent'    => $agentId,
        'currentStep'     => $stepIndex,  // ← $stepIndex, pas 0
        'context'         => $runPath . '/session.md',
    ]);

    // AVANT: event(new AgentStatusChanged($runId, $agentId, 'working', 0, ''));
    // APRÈS:
    event(new AgentStatusChanged($runId, $agentId, 'working', $stepIndex, ''));

    try {
        $rawOutput = $this->driver->execute(...);
    } catch (CliExecutionException $e) {
        // AVANT: event(new AgentStatusChanged($runId, $agentId, 'error', 0, $e->getMessage()));
        // APRÈS:
        event(new AgentStatusChanged($runId, $agentId, 'error', $stepIndex, $e->getMessage()));
        event(new RunError(              // ← NOUVEAU : émettre RunError ici
            runId:          $runId,
            agentId:        $agentId,
            step:           $stepIndex,
            message:        $e->getMessage(),
            checkpointPath: $runPath . '/checkpoint.json',
        ));
        throw new CliExecutionException($agentId, $e->exitCode, $e->stderr);
    } catch (ProcessTimedOutException) {
        $msg = "Timeout after {$timeout}s";
        event(new AgentStatusChanged($runId, $agentId, 'error', $stepIndex, $msg));
        event(new RunError(              // ← NOUVEAU
            runId:          $runId,
            agentId:        $agentId,
            step:           $stepIndex,
            message:        $msg,
            checkpointPath: $runPath . '/checkpoint.json',
        ));
        throw new AgentTimeoutException($agentId, $timeout);
    }

    $decoded = $this->validateJsonOutput($agentId, $rawOutput);  // peut throw InvalidJsonOutputException

    $this->artifactService->appendAgentOutput($runPath, $agentId, $rawOutput);

    $completedAgents[] = $agentId;

    // ← NOUVEAU : Checkpoint POST-COMPLETION (NFR6)
    // Écrit AVANT l'événement 'done' — completedAgents inclut maintenant $agentId
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
    // AVANT: event(new AgentBubble($runId, $agentId, $bubbleMessage, 0));
    // APRÈS:
    event(new AgentBubble($runId, $agentId, $bubbleMessage, $stepIndex));
    // AVANT: event(new AgentStatusChanged($runId, $agentId, 'done', 0, ''));
    // APRÈS:
    event(new AgentStatusChanged($runId, $agentId, 'done', $stepIndex, ''));

    $agentResults[] = ['id' => $agentId, 'status' => $decoded['status']];
}
```

**Gérer `InvalidJsonOutputException` :** cette exception est lancée par `validateJsonOutput()`. Elle ne passe pas par les `try/catch` internes actuels — ajouter un catch externe ou envelopper `validateJsonOutput()` dans le bloc try :

```php
try {
    $rawOutput = $this->driver->execute(...);
    $decoded   = $this->validateJsonOutput($agentId, $rawOutput); // ← déplacer dans le try
} catch (CliExecutionException $e) { ... }
  catch (ProcessTimedOutException $e) { ... }
  catch (InvalidJsonOutputException $e) {  // ← NOUVEAU catch
    $msg = "Invalid JSON output from {$agentId}: {$e->getMessage()}";
    event(new AgentStatusChanged($runId, $agentId, 'error', $stepIndex, $msg));
    event(new RunError(
        runId: $runId, agentId: $agentId, step: $stepIndex,
        message: $msg, checkpointPath: $runPath . '/checkpoint.json',
    ));
    throw $e;
}
```

---

### §SseController — éviter la double-émission de RunError

Comme `RunService` émet maintenant `RunError` pour les erreurs connues, le catch-all dans `SseController` ne doit pas en émettre un deuxième. Solution simple : utiliser un flag via cache.

```php
// Dans SseController::stream() catch :
catch (\Throwable $e) {
    // RunService a déjà émis RunError si l'exception vient d'un agent connu.
    // Seule exception : erreurs inattendues non gérées dans RunService.
    // On vérifie si RunError a déjà été émis via un flag en cache.
    if (! cache()->has("run:{$id}:error_emitted")) {
        event(new RunError(
            runId:          $id,
            agentId:        'unknown',
            step:           0,
            message:        $e->getMessage(),
            checkpointPath: '',
        ));
    }
}
```

Dans `RunService`, après chaque `event(new RunError(...))` interne, ajouter :
```php
cache()->put("run:{$runId}:error_emitted", true, 60);
```

Et dans le bloc `finally` de `RunService`, ajouter :
```php
cache()->forget("run:{$runId}:error_emitted");
```

---

### §Tests — CheckpointServiceTest structure

```php
<?php
// backend/tests/Unit/CheckpointServiceTest.php
namespace Tests\Unit;

use App\Services\CheckpointService;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class CheckpointServiceTest extends TestCase
{
    private string $tmpDir;
    private CheckpointService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir() . '/checkpoint-test-' . uniqid();
        File::makeDirectory($this->tmpDir, 0755, true);
        $this->service = new CheckpointService();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->tmpDir);
        parent::tearDown();
    }

    // write() tests
    public function test_write_creates_checkpoint_json(): void { ... }
    public function test_write_creates_valid_json_with_correct_schema(): void { ... }
    public function test_write_includes_completed_agents(): void { ... }
    public function test_write_sanitises_credentials((): void { ... } // même pattern ArtifactServiceTest

    // read() tests
    public function test_read_returns_exact_written_data(): void { ... }
    public function test_read_throws_if_file_absent(): void {
        $this->expectException(\RuntimeException::class);
        $this->service->read('/nonexistent/path');
    }
    public function test_read_throws_if_json_invalid(): void {
        File::put($this->tmpDir . '/checkpoint.json', 'not-valid-json');
        $this->expectException(\RuntimeException::class);
        $this->service->read($this->tmpDir);
    }

    // NFR6 crash safety
    public function test_write_then_read_survives_simulated_crash(): void {
        // Écrire checkpoint avec agentA complété
        $this->service->write($this->tmpDir, [
            'runId' => 'test', 'workflowFile' => 'f.yaml', 'brief' => 'b',
            'completedAgents' => ['agent-a'], 'currentAgent' => 'agent-b',
            'currentStep' => 1, 'context' => $this->tmpDir . '/session.md',
        ]);
        // Simuler crash → relire → vérifier intégrité
        $data = $this->service->read($this->tmpDir);
        $this->assertEquals(['agent-a'], $data['completedAgents']);
        $this->assertEquals('agent-b', $data['currentAgent']);
        $this->assertEquals(1, $data['currentStep']);
    }
}
```

---

### §Ordre des opérations post-completion (NFR6) — séquence garantie

```
Agent X execute() → succès
  ↓
$completedAgents[] = $agentId             ← X ajouté à la liste
  ↓
checkpointService->write([completedAgents: [..., X], currentAgent: X+1, currentStep: n+1])  ← DISQUE
  ↓  ← crash ici → checkpoint contient X comme complété ✓
getContextContent()
  ↓
event(AgentBubble)
  ↓
event(AgentStatusChanged 'done')          ← SEULEMENT après écriture disque
```

---

### §Guardrails — Erreurs critiques à éviter

| ❌ Interdit | ✅ Correct |
|---|---|
| Checkpoint post-completion APRÈS `event(done)` | Écrire checkpoint AVANT `event(AgentBubble)` et `event(done)` |
| `step: 0` hardcodé dans les events | `$stepIndex` depuis `foreach (...as $stepIndex => $agent)` |
| Double-émission `RunError` (RunService + SseController) | Utiliser flag `run:{id}:error_emitted` en cache |
| `validateJsonOutput()` hors du bloc try/catch | Inclure dans le bloc try pour catcher `InvalidJsonOutputException` |
| `CheckpointService::read()` silencieux sur fichier absent | Toujours lancer `\RuntimeException` si fichier absent ou JSON invalide |
| Dupliquer la logique `sanitizeEnvCredentials` avec dérive | Copier exactement le pattern de `ArtifactService` (même regex, même seuil 8 chars) |
| Supprimer `ArtifactService::writeCheckpoint()` dans cette story | `ArtifactService::initializeRun()` en dépend — ne pas toucher |
| Créer un `RunException` custom complexe | Utiliser le flag cache pour la coordination RunService ↔ SseController |

---

### §Fichiers à créer / modifier

```
backend/app/Services/CheckpointService.php           ← CRÉER
backend/app/Services/RunService.php                  ← MODIFIER (inject CheckpointService, step, 2ème write, RunError, flag cache)
backend/app/Http/Controllers/SseController.php       ← MODIFIER (guard double RunError via flag cache)
backend/tests/Unit/CheckpointServiceTest.php         ← CRÉER
```

**Ne pas toucher :**
- `ArtifactService.php` — `writeCheckpoint` reste pour `initializeRun`
- `frontend/` — aucune modification frontend requise (`useSSEListener` traite déjà `step` et `run.error` avec les bons champs, il recevra maintenant des valeurs correctes)
- Events PHP (`AgentStatusChanged`, `RunError`, `AgentBubble`) — signatures inchangées

---

### Project Structure Notes

- `CheckpointService` va dans `backend/app/Services/` — pas de sous-dossier (cohérent avec `RunService`, `ArtifactService`)
- Tests unitaires dans `backend/tests/Unit/` — pattern `*Test.php`, extend `Tests\TestCase`
- `use Illuminate\Support\Facades\File` dans `CheckpointService` (pas `Storage` — les chemins sont absolus, pas dans le disque configuré)
- Laravel DI résout `CheckpointService` automatiquement via autowiring — pas de binding explicite dans `AppServiceProvider`
- Ajouter `use App\Services\CheckpointService` et `use App\Events\RunError` dans `RunService`
- Lire `frontend/AGENTS.md` → `frontend/CLAUDE.md` si touche le frontend (mais cette story est backend only)

### References

- [Source: docs/planning-artifacts/epics.md#Story-3.1] — User story, ACs complets (FR24, NFR6)
- [Source: docs/planning-artifacts/architecture.md#Data-Architecture] — Schéma checkpoint.json, structure dossier run
- [Source: docs/planning-artifacts/architecture.md#API-Communication-Patterns] — 4 types événements SSE (RunError avec checkpointPath)
- [Source: docs/planning-artifacts/architecture.md#Process-Patterns] — NFR7 : erreurs CLI capturées sans crash silencieux
- [Source: docs/implementation-artifacts/epic-2-retro-2026-04-09.md#Dette-technique] — 🔴 step:0 hardcodé (A2 bloquant), checkpointPath vide, A5 patch config timeout
- [Source: backend/app/Services/RunService.php] — Séquence complète execute(), foreach agents, writeCheckpoint position actuelle
- [Source: backend/app/Services/ArtifactService.php#writeCheckpoint] — Logique sanitize à dupliquer dans CheckpointService
- [Source: backend/app/Http/Controllers/SseController.php#L34-42] — catch-all RunError à conditionner
- [Source: backend/app/Events/RunError.php] — Signature : runId, agentId, step, message, checkpointPath
- [Source: backend/tests/Unit/ArtifactServiceTest.php] — Pattern de tests unitaires à suivre
- [Source: frontend/src/hooks/useSSEListener.ts#L51-65] — Gère run.error : setAgentStatus(agentId, error, step, message) + setRunError(message) — recevra maintenant les bons champs

## Dev Agent Record

### Agent Model Used

claude-sonnet-4-6

### Debug Log References

- `validateJsonOutput()` déplacé dans le bloc try/catch interne pour que `InvalidJsonOutputException` soit capturée et génère un `RunError` correct (avec `agentId` et `step` réels).
- Double-émission `RunError` évitée via flag cache `run:{id}:error_emitted` posé par `RunService` et consommé dans le catch-all de `SseController`. Flag supprimé dans le `finally` de `RunService`.
- `RunServiceTest` et `RunServiceTimeoutTest` mis à jour pour injecter `CheckpointService` mock (4ème paramètre du constructeur).
- `getContextContent` : appel initial avant le foreach + 1 appel par agent après completion → inchangé (3 appels pour 2 agents, cohérent avec les tests existants).

### Completion Notes List

- `CheckpointService` créé : `write()` (JSON + sanitize credentials NFR12) et `read()` (RuntimeException si absent/invalide)
- `RunService` : `CheckpointService` injecté, `step: 0` corrigé → `$stepIndex` depuis `foreach (...as $stepIndex => $agent)`, checkpoint pré-agent conservé + checkpoint post-completion ajouté AVANT `event(done)` (NFR6), `RunError` émis depuis les 3 catch internes avec `agentId`/`step`/`checkpointPath` réels, patch A5 timeout config
- `SseController` : guard flag `run:{id}:error_emitted` évite la double-émission `RunError`
- Tests : 12 nouveaux tests `CheckpointServiceTest` (write/read/crash safety/sanitize), mise à jour `RunServiceTest` + `RunServiceTimeoutTest` pour 4ème paramètre constructeur
- Suite complète : 74/74 verts (206 assertions), 0 régression

### File List

- backend/app/Services/CheckpointService.php (créé)
- backend/app/Services/RunService.php (modifié)
- backend/app/Http/Controllers/SseController.php (modifié)
- backend/tests/Unit/CheckpointServiceTest.php (créé)
- backend/tests/Unit/RunServiceTest.php (modifié)
- backend/tests/Unit/RunServiceTimeoutTest.php (modifié)
