# Story 2.4 : Stream SSE — événements temps réel Laravel → Next.js

Status: done
Epic: 2
Story: 4
Date: 2026-04-07

## Story

As a développeur,
I want recevoir en temps réel les événements du run via SSE,
so that le frontend peut mettre à jour le diagramme et les bulles sans polling.

## Acceptance Criteria

1. **Given** un run démarré via `POST /api/runs` — **When** le client ouvre `GET /api/runs/{id}/stream` — **Then** le stream SSE reste ouvert pendant toute la durée du run
2. **Given** le stream SSE ouvert — **Then** Laravel émet les 4 types d'événements normalisés via `event(new ...)` : `agent.status.changed`, `agent.bubble`, `run.completed`, `run.error`
3. **Given** tout payload SSE émis — **Then** il est en camelCase avec timestamps ISO 8601 (jamais de timestamp Unix)
4. **Given** un run terminé ou en erreur — **Then** le stream se ferme automatiquement à `run.completed` ou `run.error`
5. **Given** un run actif — **Then** la latence entre un événement agent et son émission SSE est inférieure à 200ms en conditions localhost (NFR1)

## Architecture SSE retenue — LIRE AVANT DE COMMENCER

### Flux exact (critique à comprendre)

```
POST /api/runs { workflowFile, brief }
  → Génère runId (UUID), stocke config en cache (clé: run:{runId}:config)
  → Retourne immédiatement : { runId, status: "pending" } HTTP 202
  (aucune exécution dans le POST — juste allocation du runId)

GET /api/runs/{id}/stream
  → SseController lit la config depuis cache(run:{id}:config)
  → StreamedResponse (Content-Type: text/event-stream)
  → RunService::execute($runId, $workflowFile, $brief) est appelé ICI
  → Pendant l'exécution : event(new AgentStatusChanged(...)) etc.
  → SseEmitter écrit directement dans la sortie PHP avec echo + flush()
  → Stream fermé quand run.completed ou run.error
```

### Pourquoi ce design

- `EventSource` (GET-only, NFR5) — impossible d'ouvrir un EventSource sur POST
- Pas de base de données → pas de queue Laravel standard
- `php artisan serve` gère les connexions concurrentes (child processes PHP 8.x)
- Annulation via `DELETE /api/runs/{id}` reste fonctionnelle (flag cache check dans RunService)
- Aucun daemon queue supplémentaire requis

### Ce que ça change vs story 2.3

| Avant (2.3) | Après (2.4) |
|---|---|
| `POST /api/runs` retourne 201 + résultat complet | `POST /api/runs` retourne 202 + `{ runId, status: "pending" }` |
| `RunService::execute(workflowFile, brief)` génère son propre runId | `RunService::execute(runId, workflowFile, brief)` reçoit le runId du Controller |
| Aucun événement SSE | Événements SSE émis pendant l'exécution dans le GET /stream |
| `RunApiTest` attend 201 + body complet | `RunApiTest` mise à jour pour 202 + `{ runId, status }` |

## Tasks / Subtasks

- [x] **T1 — Modifier `RunController::store()` pour retourner 202 immédiatement** (AC 1)
  - [x] Générer `$runId = Str::uuid()->toString()` dans le Controller (plus dans RunService)
  - [x] Stocker la config en cache : `cache()->put("run:{$runId}:config", ['workflowFile' => ..., 'brief' => ...], 3600)`
  - [x] Retourner `response()->json(['runId' => $runId, 'status' => 'pending'], 202)`
  - [x] **NE PAS** appeler `$this->runService->execute()` dans `store()`

- [x] **T2 — Créer les 4 classes Event SSE** (AC 2, 3)
  - [x] `backend/app/Events/AgentStatusChanged.php`
  - [x] `backend/app/Events/AgentBubble.php`
  - [x] `backend/app/Events/RunCompleted.php`
  - [x] `backend/app/Events/RunError.php`
  - [x] Chaque Event implements `ShouldBroadcast` **NON** — utiliser `Illuminate\Foundation\Events\Dispatchable`
  - [x] Stocker les données du payload en propriété publique `readonly` sur l'Event

- [x] **T3 — Créer `SseEmitter` Listener** (AC 2, 3)
  - [x] `backend/app/Listeners/SseEmitter.php`
  - [x] Méthode `handle($event)` : détecter le type d'événement, formater le payload SSE, écrire et flusher
  - [x] Format SSE exact : `"event: {type}\ndata: {json}\n\n"` + `ob_flush()` + `flush()`
  - [x] Enregistrer dans `EventServiceProvider` pour les 4 Event classes

- [x] **T4 — Créer `SseStreamService`** (AC 1, 4, 5)
  - [x] `backend/app/Services/SseStreamService.php`
  - [x] Méthode `setHeaders()` : configurer les headers SSE sur la response
  - [x] Méthode `sendKeepAlive()` : émettre un commentaire SSE (`: ping\n\n`) pour garder la connexion

- [x] **T5 — Créer `SseController`** (AC 1, 4)
  - [x] `backend/app/Http/Controllers/SseController.php`
  - [x] Méthode `stream(string $id)` : retourner `StreamedResponse`
  - [x] Lire config depuis cache, valider existance
  - [x] Appeler `RunService::execute($id, $workflowFile, $brief)` dans la closure StreamedResponse
  - [x] Wrapper l'appel dans un try/catch qui émet `RunError` si exception levée

- [x] **T6 — Mettre à jour `RunService::execute()`** (AC 2, 3, 5)
  - [x] Signature : `execute(string $runId, string $workflowFile, string $brief): void` (plus de retour de tableau)
  - [x] Supprimer la génération `Str::uuid()` dans RunService (runId reçu en param)
  - [x] Avant chaque agent : `event(new AgentStatusChanged($runId, $agentId, 'working', 0, ''))` 
  - [x] Après chaque agent réussi : `event(new AgentBubble($runId, $agentId, $decoded['output'], 0))`
  - [x] Puis : `event(new AgentStatusChanged($runId, $agentId, 'done', 0, ''))`
  - [x] En cas d'exception : `event(new AgentStatusChanged($runId, $agentId, 'error', 0, $e->getMessage()))` puis rethrow
  - [x] À la fin de tous les agents : `event(new RunCompleted($runId, $duration, count($agentResults), 'completed', $runPath))`
  - [x] Le SseController catchera et émettra `RunError` si RunService lève une exception

- [x] **T7 — Enregistrer les events dans `EventServiceProvider`** (AC 2)
  - [x] `backend/app/Providers/AppServiceProvider.php` — enregistrement via `Event::listen()` dans `boot()`
  - [x] 4 events mappés sur `SseEmitter` avec les méthodes `handle*` dédiées

- [x] **T8 — Mettre à jour les routes** (AC 1)
  - [x] Ajouter dans `routes/api.php` : `Route::get('/runs/{id}/stream', [SseController::class, 'stream'])`

- [x] **T9 — Mettre à jour les tests** (régressions)
  - [x] `RunApiTest` : réécriture complète — 202 + `{ runId, status: "pending" }`, tests du cache, validation format
  - [x] Créer `backend/tests/Feature/SseControllerTest.php` — 404, headers SSE, execute() appelé, RunError sur throw
  - [x] `RunServiceTest` : signature mise à jour, `execute()` void, `Event::fake()`, vérification events
  - [x] `RunServiceTimeoutTest` : signature mise à jour, `Event::fake()`, suppression `forceUuid` (runId passé directement)
  - [x] 62/62 tests verts — 0 régression

- [x] **T10 — Vérification manuelle**
  - [x] `php artisan test` — 62/62 ✅

### Review Findings (2026-04-07)

- [x] [Review][Patch] `run:{id}:config` ne doit PAS être supprimé dans `finally` — conserver la clé jusqu'à expiration du TTL pour permettre la reconnexion SSE native [RunService.php:finally]
- [x] [Review][Defer] `sendKeepAlive()` jamais appelé — localhost sans proxy, timeout non bloquant pour MVP ; à activer quand un proxy Nginx est introduit — deferred, hors scope MVP localhost
- [x] [Review][Patch] `destroy()` vérifie `run:{$id}` mais `store()` écrit `run:{$id}:config` — DELETE retourne 404 avant ouverture du stream SSE, cancellation inopérante en phase pending [RunController.php:33]
- [x] [Review][Patch] Double exécution si deux `GET /runs/{id}/stream` concurrents — aucun mutex, le workflow tourne deux fois en parallèle [SseController.php:19]
- [x] [Review][Patch] `ob_end_clean()` ne ferme qu'un seul niveau de buffer — peut laisser des buffers FPM imbriqués actifs, rendant `ob_flush()` inefficace [SseStreamService.php:9]
- [x] [Review][Patch] `(string) $decoded['output']` produit la chaîne `"Array"` si `output` est un tableau JSON — AgentBubble reçoit une donnée inutilisable [RunService.php:88]
- [x] [Review][Patch] Aucun signal terminal SSE (`retry: 0`) dans `run.completed`/`run.error` — le browser reconnecte immédiatement après fin de run, produit une boucle de 404 [SseEmitter.php:handleRunCompleted/handleRunError]
- [x] [Review][Patch] `RunError` émis avec `agentId: ''` depuis le catch du SseController — impossible de savoir quel agent a causé l'erreur côté frontend [SseController.php:30]
- [x] [Review][Defer] Pas d'authentification sur les routes SSE/DELETE — deferred, localhost single-user, auth hors scope MVP
- [x] [Review][Defer] `step: 0` hardcodé dans tous les events — deferred, granularité step-level prévue en Epic 3 (CheckpointService)
- [x] [Review][Defer] `runFolder` et `checkpointPath` exposent des chemins serveur absolus dans les payloads SSE — deferred, localhost single-user, sécurité hors scope MVP
- [x] [Review][Defer] Collision `ArtifactService::initializeRun()` si deux runs dans la même seconde — deferred, pré-existant story 2.3, hors scope 2.4
- [x] [Review][Defer] `RunCancelledException` émis comme `RunError` — impossible de distinguer cancel intentionnel d'une erreur — deferred, pas de `RunCancelled` event par design MVP
- [x] [Review][Defer] Message `CliExecutionException` contient `'claude'` au lieu de l'agentId réel — deferred, pré-existant dans `ClaudeDriver`, hors scope 2.4
- [x] [Review][Defer] `brief` sans validation `max:` — deferred, hardening sécurité hors scope MVP
- [x] [Review][Defer] `resolveSystemPrompt()` retourne `''` silencieusement si fichier absent — deferred, pré-existant story 2.1
- [x] [Review][Defer] `SseEmitter` listeners enregistrés globalement — `ob_flush()` appellé hors contexte HTTP si RunService invoqué depuis Artisan — deferred, pas de callers non-HTTP actuellement

## Dev Notes

### §Classes Event — Schémas exacts des payloads

**Important : les données des events sont des propriétés `readonly` publiques (pas de getters nécessaires).**

```php
// AgentStatusChanged.php
use Illuminate\Foundation\Events\Dispatchable;

class AgentStatusChanged
{
    use Dispatchable;

    public function __construct(
        public readonly string $runId,
        public readonly string $agentId,
        public readonly string $status,  // 'working' | 'idle' | 'error' | 'done'
        public readonly int    $step,
        public readonly string $message,
    ) {}
}

// AgentBubble.php
class AgentBubble
{
    use Dispatchable;
    public function __construct(
        public readonly string $runId,
        public readonly string $agentId,
        public readonly string $message,
        public readonly int    $step,
    ) {}
}

// RunCompleted.php
class RunCompleted
{
    use Dispatchable;
    public function __construct(
        public readonly string $runId,
        public readonly int    $duration,   // ms
        public readonly int    $agentCount,
        public readonly string $status,     // 'completed'
        public readonly string $runFolder,
    ) {}
}

// RunError.php
class RunError
{
    use Dispatchable;
    public function __construct(
        public readonly string $runId,
        public readonly string $agentId,
        public readonly int    $step,
        public readonly string $message,
        public readonly string $checkpointPath,
    ) {}
}
```

### §Payloads SSE exacts (camelCase + ISO 8601)

```json
// agent.status.changed
{"runId":"uuid","agentId":"pm","status":"working","step":0,"message":"","timestamp":"2026-04-07T14:30:00Z"}

// agent.bubble
{"runId":"uuid","agentId":"pm","message":"Analyse du brief terminée...","step":0,"timestamp":"2026-04-07T14:30:05Z"}

// run.completed
{"runId":"uuid","duration":12500,"agentCount":3,"status":"completed","runFolder":"/abs/path/runs/2026-04-07-1430","timestamp":"2026-04-07T14:30:12Z"}

// run.error
{"runId":"uuid","agentId":"laravel-dev","step":0,"message":"CLI process timeout after 120s","checkpointPath":"/abs/path/runs/2026-04-07-1430/checkpoint.json","timestamp":"2026-04-07T14:30:08Z"}
```

### §SseEmitter — pattern d'écriture dans le stream

```php
// app/Listeners/SseEmitter.php
class SseEmitter
{
    public function handleAgentStatusChanged(AgentStatusChanged $event): void
    {
        $payload = json_encode([
            'runId'     => $event->runId,
            'agentId'   => $event->agentId,
            'status'    => $event->status,
            'step'      => $event->step,
            'message'   => $event->message,
            'timestamp' => now()->toIso8601String(),
        ], JSON_THROW_ON_ERROR);

        echo "event: agent.status.changed\n";
        echo "data: {$payload}\n\n";
        ob_flush();
        flush();
    }

    // handleAgentBubble(), handleRunCompleted(), handleRunError() — même pattern
}
```

**Règle critique** : toujours `ob_flush()` + `flush()` après chaque écriture — sans ça le client ne reçoit rien.

### §SseController — StreamedResponse avec headers corrects

```php
// app/Http/Controllers/SseController.php
class SseController extends Controller
{
    public function __construct(
        private readonly RunService        $runService,
        private readonly SseStreamService  $sseStreamService,
    ) {}

    public function stream(string $id): StreamedResponse
    {
        $config = cache()->get("run:{$id}:config");

        if (! $config) {
            abort(404, "Run not found: {$id}");
        }

        return new StreamedResponse(function () use ($id, $config) {
            $this->sseStreamService->setHeaders();

            try {
                $this->runService->execute($id, $config['workflowFile'], $config['brief']);
            } catch (\Throwable $e) {
                event(new RunError(
                    runId:          $id,
                    agentId:        '',
                    step:           0,
                    message:        $e->getMessage(),
                    checkpointPath: '',
                ));
            }
        }, 200, [
            'Content-Type'      => 'text/event-stream',
            'Cache-Control'     => 'no-cache',
            'X-Accel-Buffering' => 'no',  // désactiver le buffering nginx si présent
            'Connection'        => 'keep-alive',
        ]);
    }
}
```

### §SseStreamService — headers et keep-alive

```php
// app/Services/SseStreamService.php
class SseStreamService
{
    public function setHeaders(): void
    {
        // Désactiver le buffering PHP pour ce stream
        if (ob_get_level() > 0) {
            ob_end_clean();
        }
        ob_implicit_flush(true);
    }

    public function sendKeepAlive(): void
    {
        echo ": ping\n\n";
        ob_flush();
        flush();
    }
}
```

### §RunService — nouvelle signature et émission d'événements

```php
// Nouvelle signature — retourne void, reçoit runId
public function execute(string $runId, string $workflowFile, string $brief): void
{
    $workflow = $this->yamlService->load($workflowFile);
    $createdAt = now()->toIso8601String();
    $startedAt = microtime(true);

    cache()->put("run:{$runId}", ['status' => 'running', 'startedAt' => $createdAt], 3600);

    $agentResults    = [];
    $completedAgents = [];

    try {
        $runPath = $this->artifactService->initializeRun($runId, $workflowFile, $brief);
        $context = $this->artifactService->getContextContent($runPath);

        foreach ($workflow['agents'] as $agent) {
            $agentId = $agent['id'];

            if (cache()->get("run:{$runId}:cancelled", false)) {
                throw new RunCancelledException($runId);
            }

            // Émettre 'working' avant spawn
            event(new AgentStatusChanged($runId, $agentId, 'working', 0, ''));

            // ... timeout, systemPrompt, checkpoint write (identique story 2.3) ...

            $rawOutput = $this->driver->execute(...);
            $decoded   = $this->validateJsonOutput($agentId, $rawOutput);

            $this->artifactService->appendAgentOutput($runPath, $agentId, $rawOutput);
            $completedAgents[] = $agentId;
            $context = $this->artifactService->getContextContent($runPath);

            // Émettre bubble + done après agent réussi
            event(new AgentBubble($runId, $agentId, (string) $decoded['output'], 0));
            event(new AgentStatusChanged($runId, $agentId, 'done', 0, ''));

            $agentResults[] = ['id' => $agentId, 'status' => $decoded['status']];
        }

        $duration = (int) round((microtime(true) - $startedAt) * 1000);

        event(new RunCompleted($runId, $duration, count($agentResults), 'completed', $runPath));

    } catch (AgentTimeoutException | CliExecutionException $e) {
        $lastAgentId      = $agentResults ? end($agentResults)['id'] : '';
        $checkpointPath   = isset($runPath) ? $runPath . '/checkpoint.json' : '';
        event(new AgentStatusChanged($runId, $lastAgentId, 'error', 0, $e->getMessage()));
        // RunError est émis par SseController::stream() dans son catch global
        throw $e;
    } finally {
        cache()->forget("run:{$runId}");
        cache()->forget("run:{$runId}:cancelled");
    }
}
```

⚠️ `RunService::execute()` rethrow les exceptions — `SseController::stream()` émet `RunError` dans son catch `\Throwable`.

### §RunController::store() — nouveau comportement

```php
public function store(Request $request): JsonResponse
{
    $validated = $request->validate([
        'workflowFile' => ['required', 'string', 'regex:/^[\w\-]+\.ya?ml$/'],
        'brief'        => ['required', 'string'],
    ]);

    $runId = Str::uuid()->toString();

    cache()->put("run:{$runId}:config", [
        'workflowFile' => $validated['workflowFile'],
        'brief'        => $validated['brief'],
    ], 3600);

    return response()->json([
        'runId'  => $runId,
        'status' => 'pending',
    ], 202);
}
```

### §EventServiceProvider — enregistrement

```php
// app/Providers/EventServiceProvider.php
// Si le fichier n'existe pas : créer et l'enregistrer dans bootstrap/app.php
protected $listen = [
    AgentStatusChanged::class => [SseEmitter::class],
    AgentBubble::class        => [SseEmitter::class],
    RunCompleted::class       => [SseEmitter::class],
    RunError::class           => [SseEmitter::class],
];
```

Si `EventServiceProvider` n'est pas enregistré dans `bootstrap/app.php`, ajouter :
```php
->withProviders([
    App\Providers\EventServiceProvider::class,
])
```

### §Mise à jour des routes

```php
// routes/api.php — ajouter :
Route::get('/runs/{id}/stream', [SseController::class, 'stream']);
```

### §Mise à jour des tests

**`RunApiTest` — changer les assertions POST :**
```php
// Avant :
$response->assertStatus(201);
$response->assertJsonStructure(['runId', 'status', 'agents', 'duration', 'createdAt', 'runFolder']);

// Après :
$response->assertStatus(202);
$response->assertJsonStructure(['runId', 'status']);
$response->assertJson(['status' => 'pending']);
```

**`RunServiceTest` et `RunServiceTimeoutTest` — mettre à jour la signature :**
```php
// Avant : $this->service->execute($workflowFile, $brief)
// Après : $this->service->execute($runId, $workflowFile, $brief)
// Ajouter $runId = 'test-uuid' dans les tests existants
// execute() retourne void — pas d'assertion sur la valeur de retour
// Ajouter expectation sur les events : Event::fake(); ... Event::assertDispatched(AgentStatusChanged::class)
```

### §Guardrails — Erreurs critiques à éviter

| ❌ Interdit | ✅ Correct |
|---|---|
| `EventSource` instancié dans un composant React | `useSSEListener` hook custom uniquement (story 2.5) |
| Émettre SSE directement dans un Controller | `event(new EventClass(...))` → SseEmitter Listener |
| Timestamp Unix dans un payload SSE | `now()->toIso8601String()` ISO 8601 partout |
| `echo` SSE dans `RunService` | SseEmitter est le seul point d'écriture SSE |
| Appeler `RunService::execute()` dans `RunController::store()` | Exécuter RunService dans `SseController::stream()` uniquement |
| `ShouldBroadcast` sur les Events (qui envoie vers Pusher/WebSocket) | `Dispatchable` trait uniquement — SseEmitter écrit directement en sortie |
| Oublier `ob_flush()` + `flush()` après echo | Toujours les deux après chaque événement SSE |
| `X-Accel-Buffering: no` absent | Nécessaire pour désactiver le buffering nginx en cas de proxy |
| Modifier `DriverInterface`, `ClaudeDriver`, `GeminiDriver` | Aucun changement requis dans les drivers |
| Modifier `ArtifactService` | Aucun changement requis |

### §Portée délimitée — Ce qui n'appartient PAS à cette story

- **Hook `useSSEListener`** → Story 2.5 (client SSE Next.js)
- **Zustand stores** (`runStore`, `agentStatusStore`) → Story 2.5
- **`LaunchBar`** (appel POST + ouverture SSE) → Story 2.7a
- **`BubbleBox`** et `RunSidebar` → Story 2.7b
- **Retry depuis checkpoint** → Story 3.4
- **Reconnexion EventSource automatique** → gérée nativement par EventSource côté client (NFR5)

### §Fichiers à créer/modifier

```
backend/
├── app/
│   ├── Events/
│   │   ├── AgentStatusChanged.php   ← CRÉER
│   │   ├── AgentBubble.php          ← CRÉER
│   │   ├── RunCompleted.php         ← CRÉER
│   │   └── RunError.php             ← CRÉER
│   ├── Listeners/
│   │   └── SseEmitter.php           ← CRÉER
│   ├── Services/
│   │   ├── RunService.php           ← MODIFIER (signature, retour void, emit events)
│   │   └── SseStreamService.php     ← CRÉER
│   ├── Http/
│   │   └── Controllers/
│   │       ├── RunController.php    ← MODIFIER (store: 202, pas d'execute)
│   │       └── SseController.php    ← CRÉER
│   └── Providers/
│       └── EventServiceProvider.php ← CRÉER (si inexistant)
├── routes/
│   └── api.php                      ← MODIFIER (ajouter GET /runs/{id}/stream)
└── tests/
    ├── Feature/
    │   ├── RunApiTest.php           ← MODIFIER (assertions 202)
    │   └── SseControllerTest.php   ← CRÉER
    └── Unit/
        ├── RunServiceTest.php       ← MODIFIER (signature, void, events)
        └── RunServiceTimeoutTest.php ← MODIFIER (signature)
```

**Pas de changement dans :** `ArtifactService`, `YamlService`, `DriverInterface`, `ClaudeDriver`, `GeminiDriver`, `WorkflowController`, `RunResource`.

### §Vérification

```bash
# Tests
cd backend && php artisan test
# Objectif : tous les tests verts, 0 régression

# Test manuel — POST (retourne 202 + runId)
curl -s -X POST http://localhost:8000/api/runs \
  -H "Content-Type: application/json" \
  -d '{"workflowFile":"example-feature-dev.yaml","brief":"Test SSE 2.4"}' | python3 -m json.tool
# Expected: { "runId": "uuid", "status": "pending" }   HTTP 202

# Test manuel — stream SSE (dans un autre terminal, ouvrir AVANT ou juste après le POST)
# runId = valeur reçue du POST ci-dessus
curl -N \
  -H "Accept: text/event-stream" \
  -H "Cache-Control: no-cache" \
  http://localhost:8000/api/runs/{runId}/stream
# Expected: flux d'événements SSE visibles en temps réel :
# event: agent.status.changed
# data: {"runId":"...","agentId":"pm","status":"working",...}
#
# event: agent.bubble
# data: {"runId":"...","agentId":"pm","message":"...","step":0,...}
# ...
# event: run.completed
# data: {"runId":"...","duration":...,"agentCount":...,...}

# Test stream avec runId inexistant → 404
curl -s http://localhost:8000/api/runs/fake-id/stream
# Expected: 404
```

### §Appel depuis l'environnement de dev

Le `GET /api/runs/{id}/stream` s'exécute en long-running HTTP dans un child process PHP. Pour des tests manuels complets : ouvrir deux terminaux simultanément (un pour le POST, un pour le curl SSE) — `php artisan serve` gère la concurrence en PHP 8.x.

### §Apprentissages de la story 2.3 applicables

- `ob_flush()` + `flush()` : indispensables pour les sorties en temps réel (pattern déjà utilisé dans les tests, transposé ici pour le SSE)
- `cache()->forget()` dans `finally` : conserver ce pattern dans le nouveau `execute()` pour nettoyer les clés de cache
- `RunService` injecte ses dépendances via constructeur — `SseController` l'injecte aussi — pas de `new RunService()` direct
- Les tests avec `Event::fake()` (Laravel) captureront les events sans les envoyer réellement au SseEmitter — pratique pour les tests unitaires

---

### References

- [Source: docs/planning-artifacts/epics.md#Story-2.4] — user story, AC, FR18-FR23, NFR1
- [Source: docs/planning-artifacts/epics.md#Additional-Requirements] — SSE via EventSystem, useSSEListener hook, payload camelCase + ISO 8601
- [Source: docs/planning-artifacts/architecture.md#Backend-Architecture] — SseController, SseStreamService, Events/, Listeners/SseEmitter
- [Source: docs/planning-artifacts/architecture.md#Communication-Patterns] — pattern `event(new EventClass(...))`, jamais direct dans Controller
- [Source: docs/planning-artifacts/architecture.md#API-Design] — Format SSE 4 types, GET /api/runs/{id}/stream, payloads camelCase
- [Source: docs/planning-artifacts/architecture.md#Project-Structure] — chemins exacts : app/Events/, app/Listeners/, app/Services/SseStreamService
- [Source: docs/planning-artifacts/architecture.md#Data-Flow] — séquence POST → GET /stream → RunService → events → SseEmitter → SSE stream
- [Source: docs/implementation-artifacts/2-3-contexte-partage-inter-agents-et-artefacts-de-run.md#Dev-Notes] — RunService actuel, ArtifactService injection, patterns finally/cache
- [Source: backend/app/Services/RunService.php] — implémentation à modifier (signature, retour void, emit events)
- [Source: backend/app/Http/Controllers/RunController.php] — store() à modifier (202, pas d'execute)
- [Source: backend/routes/api.php] — ajouter GET /runs/{id}/stream

## Dev Agent Record

### Agent Model Used

claude-sonnet-4-6

### Debug Log References

- `willReturn(null)` interdit sur méthode `void` en PHPUnit — supprimé du mock RunService dans SseControllerTest
- `StreamedResponse` callback ne s'exécute pas via `$this->get()` en test Laravel — résolu avec `$response->baseResponse->sendContent()` dans les tests qui doivent vérifier le comportement du callback
- `Cache-Control` header vaut `no-cache, private` (Symfony HttpFoundation ajoute `private`) — assert changé en `assertStringContainsString`
- `Event::listen()` dans Laravel 11 avec méthodes `handle*` nommées — pattern `[SseEmitter::class, 'handleAgentStatusChanged']` requis (pas de mapping automatique par nom de méthode)

### Completion Notes List

- `RunController::store()` remanié : génère runId, stocke config en cache (`run:{id}:config`), retourne 202 `{runId, status: "pending"}` — aucun appel RunService dans POST
- 4 classes Event créées (`AgentStatusChanged`, `AgentBubble`, `RunCompleted`, `RunError`) avec propriétés `readonly` publiques et trait `Dispatchable`
- `SseEmitter` Listener créé : 4 méthodes `handle*` dédiées, format SSE exact `event: {type}\ndata: {json}\n\n` + `ob_flush()` + `flush()`
- `SseStreamService` créé : `setHeaders()` (ob_end_clean + ob_implicit_flush) + `sendKeepAlive()`
- `SseController::stream()` créé : StreamedResponse avec headers SSE, appel `RunService::execute()` dans callback, catch `\Throwable` → `event(new RunError(...))`
- `RunService::execute()` refactoré : signature `(runId, workflowFile, brief): void`, events émis à chaque étape (working → bubble + done, error sur exception), `cache()->forget("run:{$runId}:config")` ajouté dans `finally`
- `AppServiceProvider::boot()` : enregistrement des 4 events → SseEmitter via `Event::listen()`
- `routes/api.php` : `GET /api/runs/{id}/stream` ajouté
- Tests : 62/62 ✅ — `RunApiTest` réécrit (6 tests POST 202), `SseControllerTest` créé (4 tests), `RunServiceTest` mis à jour (11 tests, `Event::fake()`), `RunServiceTimeoutTest` mis à jour (8 tests, `Event::fake()`, suppression `forceUuid`)

### File List

- backend/app/Http/Controllers/RunController.php (modifié — store() : 202, cache config, pas d'execute)
- backend/app/Http/Controllers/SseController.php (créé)
- backend/app/Events/AgentStatusChanged.php (créé)
- backend/app/Events/AgentBubble.php (créé)
- backend/app/Events/RunCompleted.php (créé)
- backend/app/Events/RunError.php (créé)
- backend/app/Listeners/SseEmitter.php (créé)
- backend/app/Services/RunService.php (modifié — signature void + runId param, events SSE, finally config cleanup)
- backend/app/Services/SseStreamService.php (créé)
- backend/app/Providers/AppServiceProvider.php (modifié — Event::listen() pour les 4 events)
- backend/routes/api.php (modifié — GET /runs/{id}/stream ajouté)
- backend/tests/Feature/RunApiTest.php (modifié — réécriture complète 202)
- backend/tests/Feature/SseControllerTest.php (créé — 4 tests)
- backend/tests/Unit/RunServiceTest.php (modifié — signature, void, Event::fake(), events)
- backend/tests/Unit/RunServiceTimeoutTest.php (modifié — signature, Event::fake(), suppression forceUuid)
