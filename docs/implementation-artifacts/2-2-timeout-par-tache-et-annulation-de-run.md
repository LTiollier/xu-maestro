# Story 2.2 : Timeout par tâche et annulation de run

Status: done
Epic: 2
Story: 2
Date: 2026-04-05

## Story

As a développeur,
I want que chaque agent soit interrompu automatiquement après son timeout et que je puisse annuler un run en cours,
so that aucun run ne bloque indéfiniment et je garde le contrôle.

## Acceptance Criteria

1. **Given** un agent en cours d'exécution avec un `timeout` défini dans le YAML — **When** le processus CLI dépasse la durée configurée — **Then** Laravel interrompt le processus proprement via `DriverInterface::kill()` (FR11)
2. **Given** un timeout déclenché — **Then** aucun processus CLI ne reste en état zombie après l'interruption (NFR4)
3. **When** `DELETE /api/runs/{id}` est appelé pendant un run actif — **Then** le run est marqué `cancelled` et aucun agent suivant n'est spawné ; les ressources cache sont libérées (FR13, FR26) *(Note : l'interruption d'un agent en cours d'exécution requiert le mode SSE — Story 2.4)*
4. **Given** `DELETE /api/runs/{id}` appelé — **Then** l'état du run passe à `cancelled` (registre cache) et la réponse est HTTP 202
5. **Given** aucun run actif avec l'`{id}` donné — **When** `DELETE /api/runs/{id}` est appelé — **Then** HTTP 404 `{ message, code: "RUN_NOT_FOUND" }`
6. **Given** un agent YAML sans champ `timeout` — **Then** le driver utilise `config('xu-workflow.default_timeout', 120)` (secondes)
7. **Given** un agent YAML avec `timeout: 60` — **Then** le driver utilise 60 secondes (pas le défaut global)
8. **Given** un timeout atteint — **Then** `AgentTimeoutException` est levée et exposée en HTTP 504 `{ message, code: "AGENT_TIMEOUT" }`
9. **Given** un run annulé via DELETE — **Then** `RunCancelledException` est levée et exposée en HTTP 409 `{ message, code: "RUN_CANCELLED" }`
10. **Given** `DriverInterface` — **Then** elle expose `execute(string $projectPath, string $systemPrompt, string $context, int $timeout): string` et `kill(int $pid): void` (NFR10)

## Tasks / Subtasks

- [x] **T1 — Mettre à jour `DriverInterface`** (AC 10)
  - [x] Ajouter le paramètre `int $timeout` à `execute()` → signature complète : `execute(string $projectPath, string $systemPrompt, string $context, int $timeout): string`
  - [x] Ajouter `kill(int $pid): void`

- [x] **T2 — Mettre à jour `ClaudeDriver`** (AC 7, 8, 10)
  - [x] Accepter `int $timeout` dans `execute()`, utiliser `->timeout($timeout)` à la place de `config(...)` hardcodé
  - [x] Implémenter `kill(int $pid): void` avec `posix_kill($pid, SIGTERM)` (fallback : guard `function_exists('posix_kill')`)
  - [x] Laisser `ProcessTimedOutException` remonter — RunService la convertit en `AgentTimeoutException`

- [x] **T3 — Mettre à jour `GeminiDriver`** (AC 7, 8, 10)
  - [x] Même changements que `ClaudeDriver` (`$timeout` param + `kill()`)

- [x] **T4 — Créer `AgentTimeoutException`** (AC 8)
  - [x] Créer `backend/app/Exceptions/AgentTimeoutException.php`
  - [x] Extends `\RuntimeException`
  - [x] Constructeur : `(string $agentId, int $timeout)` — message : `"Agent '{$agentId}' timed out after {$timeout} seconds"`
  - [x] Enregistrer dans `bootstrap/app.php` → HTTP 504 `{ message, code: "AGENT_TIMEOUT" }`

- [x] **T5 — Créer `RunCancelledException`** (AC 9)
  - [x] Créer `backend/app/Exceptions/RunCancelledException.php`
  - [x] Extends `\RuntimeException`
  - [x] Constructeur : `(string $runId)` — message : `"Run '{$runId}' was cancelled"`
  - [x] Enregistrer dans `bootstrap/app.php` → HTTP 409 `{ message, code: "RUN_CANCELLED" }`

- [x] **T6 — Mettre à jour `RunService`** (AC 1, 2, 3, 4, 6, 7, 8, 9)
  - [x] Stocker `$runId` dans le cache à l'entrée : `cache()->put("run:{$runId}", ['status' => 'running'], 3600)`
  - [x] Résoudre le timeout par agent : `isset($agent['timeout']) && is_int($agent['timeout']) && $agent['timeout'] > 0 ? $agent['timeout'] : (int) config('xu-workflow.default_timeout', 120)`
  - [x] Passer `$timeout` à `$this->driver->execute(..., $timeout)`
  - [x] Dans le catch `CliExecutionException` : re-throw avec `$agentId` (existant)
  - [x] Ajouter catch `ProcessTimedOutException` → `throw new AgentTimeoutException($agentId, $timeout)`
  - [x] Avant chaque agent : vérifier `cache()->get("run:{$runId}:cancelled", false)` → si vrai → `throw new RunCancelledException($runId)`
  - [x] Bloc `finally` : `cache()->forget("run:{$runId}")` + `cache()->forget("run:{$runId}:cancelled")`

- [x] **T7 — Mettre à jour `RunController`** (AC 3, 4, 5)
  - [x] Ajouter méthode `destroy(string $id): JsonResponse`
  - [x] Si `!cache()->has("run:{$id}")` → retourner HTTP 404 `{ message: "Run not found or already completed", code: "RUN_NOT_FOUND" }`
  - [x] Sinon : `cache()->put("run:{$id}:cancelled", true, 300)` → retourner HTTP 202 `{ message: "Cancellation requested", runId: $id }`

- [x] **T8 — Mettre à jour `routes/api.php`** (AC 3)
  - [x] Ajouter : `Route::delete('/runs/{id}', [RunController::class, 'destroy'])`

- [x] **T9 — Tests unitaires timeout** (couverture AC 6, 7, 8)
  - [x] Créer `backend/tests/Unit/RunServiceTimeoutTest.php`
  - [x] Tester : driver lève `ProcessTimedOutException` → `RunService` lève `AgentTimeoutException` avec le bon `$agentId` et `$timeout`
  - [x] Tester : YAML agent sans `timeout` → driver reçoit `config('xu-workflow.default_timeout', 120)`
  - [x] Tester : YAML agent avec `timeout: 45` → driver reçoit 45
  - [x] Tester : flag annulation positionné → `RunService` lève `RunCancelledException` avant le 2ème agent
  - [x] Tester : `finally` nettoie le cache dans tous les cas (succès, timeout, annulation)

- [x] **T10 — Tests feature `DELETE /api/runs/{id}`** (couverture AC 3, 4, 5)
  - [x] Créer `backend/tests/Feature/RunDeleteApiTest.php`
  - [x] Tester : `DELETE /api/runs/nonexistent-id` → HTTP 404 `{ code: "RUN_NOT_FOUND" }`
  - [x] Tester : enregistrer manuellement un run dans le cache → `DELETE /api/runs/{id}` → HTTP 202 `{ runId, message }`
  - [x] Tester : run non présent en cache → HTTP 404

- [x] **T11 — Régresser les tests existants**
  - [x] `cd backend && php artisan test` — 40/40 tests verts (9 unitaires RunServiceTest + 8 unitaires RunServiceTimeoutTest + 5 feature RunApiTest + 4 feature RunDeleteApiTest + 12 feature WorkflowControllerTest + 2 exemples)

### Review Findings (2026-04-06)

- [x] [Review][Decision] AC3 rewordé — "le run est marqué cancelled et aucun agent suivant n'est spawné" (2026-04-06)
- [x] [Review][Patch] Cache TTL mismatch corrigé — `run:{id}:cancelled` TTL 300s → 3600s [RunController.php:destroy()] (2026-04-06)
- [x] [Review][Patch] Assertion morte corrigée — déplacée dans bloc `finally` pour s'exécuter même après throw [tests/Unit/RunServiceTimeoutTest.php] (2026-04-06)
- [x] [Review][Defer] TOCTOU dans destroy() — has() + put() non-atomique : le run peut se terminer entre les deux appels, renvoyant 202 sur un run déjà complété. Inhérent au cache fichier sans CAS. [RunController.php:destroy()] — deferred, inherent file-cache limitation, acceptable MVP
- [x] [Review][Defer] Pas d'authentification sur DELETE /runs/{id} — même situation que POST /runs, concern applicatif global. [routes/api.php] — deferred, app-level concern, consistent with existing routes
- [x] [Review][Defer] Run ID renvoyé dans le message 404 — contenu user-supplied non tronqué dans la réponse JSON. Risque minimal en API JSON locale. [RunController.php:destroy()] — deferred, low risk local JSON API
- [x] [Review][Defer] Cast (int) config() peut produire 0 sur valeur non-numérique — cas marginal, config hardcodée comme int dans xu-workflow.php. [RunService.php] — deferred, config is hardcoded int
- [x] [Review][Defer] `timeout: "60"` (string YAML) utilise silencieusement le défaut global — YAML standard utilise des entiers non-quotés, cas marginal auteur workflow. [RunService.php] — deferred, YAML edge case, no MVP impact

## Dev Notes

### §CRITIQUE — Changement de signature `DriverInterface::execute()`

La signature actuelle est :
```php
public function execute(string $projectPath, string $systemPrompt, string $context): string;
```

Elle doit devenir :
```php
public function execute(string $projectPath, string $systemPrompt, string $context, int $timeout): string;
public function kill(int $pid): void;
```

**IMPORTANT :** `ClaudeDriver` et `GeminiDriver` hardcodent actuellement `->timeout(config('xu-workflow.default_timeout', 120))`. Ce config call est à SUPPRIMER des drivers — c'est désormais `RunService` qui résout le timeout et le passe.

### §Résolution du timeout par agent dans `RunService`

```php
foreach ($workflow['agents'] as $agent) {
    $agentId = $agent['id'];

    // Vérifier annulation AVANT de spawner
    if (cache()->get("run:{$runId}:cancelled", false)) {
        throw new RunCancelledException($runId);
    }

    $timeout = isset($agent['timeout']) && is_int($agent['timeout']) && $agent['timeout'] > 0
        ? $agent['timeout']
        : (int) config('xu-workflow.default_timeout', 120);

    $systemPrompt = $this->resolveSystemPrompt($agent);

    try {
        $rawOutput = $this->driver->execute(
            $workflow['project_path'],
            $systemPrompt,
            $context,
            $timeout
        );
    } catch (CliExecutionException $e) {
        throw new CliExecutionException($agentId, $e->exitCode, $e->stderr);
    } catch (ProcessTimedOutException) {
        throw new AgentTimeoutException($agentId, $timeout);
    }
    ...
}
```

Use : `use Illuminate\Process\Exceptions\ProcessTimedOutException;`

### §Registre de run — Cache Laravel

```php
public function execute(string $workflowFile, string $brief): array
{
    $workflow = $this->yamlService->load($workflowFile);
    $runId = Str::uuid()->toString();
    $createdAt = now()->toIso8601String();

    // Enregistrer le run actif dans le cache (TTL 1h)
    cache()->put("run:{$runId}", ['status' => 'running', 'startedAt' => $createdAt], 3600);

    $context = json_encode(['brief' => $brief], JSON_THROW_ON_ERROR);
    $agentResults = [];

    try {
        $startedAt = microtime(true);
        foreach ($workflow['agents'] as $agent) {
            // ... corps de la boucle ...
        }
        $duration = (int) round((microtime(true) - $startedAt) * 1000);

        return [
            'runId'     => $runId,
            'status'    => 'completed',
            'agents'    => $agentResults,
            'duration'  => $duration,
            'createdAt' => $createdAt,
        ];
    } finally {
        // NFR4 : nettoyage des ressources dans tous les cas
        cache()->forget("run:{$runId}");
        cache()->forget("run:{$runId}:cancelled");
    }
}
```

### §`RunController::destroy()` — Pattern exact

```php
public function destroy(string $id): JsonResponse
{
    if (! cache()->has("run:{$id}")) {
        return response()->json([
            'message' => "Run not found or already completed: {$id}",
            'code'    => 'RUN_NOT_FOUND',
        ], 404);
    }

    cache()->put("run:{$id}:cancelled", true, 300);

    return response()->json([
        'message' => 'Cancellation requested',
        'runId'   => $id,
    ], 202);
}
```

### §`ClaudeDriver::kill()` — Implémentation POSIX

```php
public function kill(int $pid): void
{
    if ($pid > 0 && function_exists('posix_kill')) {
        posix_kill($pid, SIGTERM);
    }
}
```

Note : `kill()` est ajouté à l'interface ici, mais son appel direct par `RunService` n'est pas possible en mode synchrone (le thread exécutant `execute()` est bloqué). L'intégration complète (appel depuis le handler SSE) arrivera en Story 2.4. En Story 2.2, `kill()` est l'infrastructure de l'interface — pas encore appelée par RunService.

### §Exception Handling — Compléter `bootstrap/app.php`

```php
use App\Exceptions\AgentTimeoutException;
use App\Exceptions\RunCancelledException;

// Dans withExceptions() — ajouter APRÈS les renderers existants :
$exceptions->render(function (AgentTimeoutException $e, Request $request) {
    return response()->json([
        'message' => $e->getMessage(),
        'code'    => 'AGENT_TIMEOUT',
    ], 504);
});

$exceptions->render(function (RunCancelledException $e, Request $request) {
    return response()->json([
        'message' => $e->getMessage(),
        'code'    => 'RUN_CANCELLED',
    ], 409);
});
```

### §Fichiers à créer/modifier

```
backend/
├── app/
│   ├── Drivers/
│   │   ├── DriverInterface.php          ← MODIFIER — ajouter kill(), int $timeout à execute()
│   │   ├── ClaudeDriver.php             ← MODIFIER — accept $timeout param, implement kill(), remove config() call
│   │   └── GeminiDriver.php             ← MODIFIER — idem ClaudeDriver
│   ├── Exceptions/
│   │   ├── AgentTimeoutException.php    ← CRÉER
│   │   └── RunCancelledException.php    ← CRÉER
│   ├── Http/
│   │   └── Controllers/
│   │       └── RunController.php        ← MODIFIER — ajouter destroy()
│   └── Services/
│       └── RunService.php               ← MODIFIER — registre cache, timeout par agent, annulation, finally
├── bootstrap/
│   └── app.php                          ← MODIFIER — 2 nouveaux renderers
├── routes/
│   └── api.php                          ← MODIFIER — DELETE /runs/{id}
└── tests/
    ├── Unit/
    │   └── RunServiceTimeoutTest.php    ← CRÉER
    └── Feature/
        └── RunApiTest.php               ← MODIFIER ou créer RunDeleteApiTest.php
```

### §Import manquant dans `RunService`

Ajouter en tête de `RunService.php` :
```php
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use App\Exceptions\AgentTimeoutException;
use App\Exceptions\RunCancelledException;
```

### §Portée de l'annulation en mode synchrone

En Story 2.2, `POST /api/runs` reste synchrone (bloque jusqu'à la fin de tous les agents). La vérification du flag d'annulation se fait **entre les agents**, pas pendant l'exécution d'un agent. Conséquences :
- Si l'agent N est en cours et le client appelle `DELETE /api/runs/{id}`, l'agent N se termine normalement
- L'agent N+1 ne sera pas spawné (flag détecté → `RunCancelledException`)
- L'annulation pendant l'exécution d'un agent requiert le modèle SSE long-running (Story 2.4)

Cette limitation est **attendue et documentée** — la story 2.4 transformera le run en long-running request SSE où `connection_aborted()` triggera le kill propre du processus actif.

### §Guardrails — Erreurs à ne pas commettre

| ❌ Interdit | ✅ Correct |
|---|---|
| `->timeout(config('xu-workflow.default_timeout', 120))` dans ClaudeDriver | Utiliser `$timeout` passé en paramètre |
| Ignorer `ProcessTimedOutException` | La catcher dans RunService → `AgentTimeoutException` |
| Ne pas enregistrer le run dans le cache | `cache()->put("run:{$runId}", ...)` au début de `execute()` |
| Oublier le `finally` | Bloc `finally` = nettoyage cache garanti (NFR4) |
| Créer de nouvelles méthodes sur YamlService | `timeout` est optionnel, pas de validation requise |
| Appeler `kill()` depuis RunService en mode sync | `kill()` est sur l'interface pour Story 2.4 — pas d'appel direct ici |
| Réimplémenter `execute()` depuis zéro dans les drivers | Modifier uniquement : signature + suppression du config() timeout |
| Casser les 28 tests existants | Mettre à jour les mocks de DriverInterface pour inclure `kill()` |

### §Impact sur les tests existants

`RunServiceTest.php` et `RunApiTest.php` mockent `DriverInterface`. Après ajout de `kill()` à l'interface, les mocks `$this->createMock(DriverInterface::class)` restent valides (PHPUnit génère un stub pour toutes les méthodes). **Aucun test existant à modifier** sauf si le mock `execute()` ne fournit pas `$timeout` → vérifier la signature.

Dans les tests unitaires existants, les appels `$mockDriver->method('execute')->willReturn(...)` restent valides car PHPUnit ne vérifie pas les paramètres par défaut. Mais les tests qui vérifient `execute` est appelé avec des paramètres précis (`->with(...)`) **doivent être mis à jour** pour inclure le `$timeout`.

### §Vérification

```bash
# Tous les tests (inclure les 28 existants + nouveaux)
cd backend && php artisan test

# Tests uniquement Story 2.2
cd backend && php artisan test --filter RunServiceTimeout
cd backend && php artisan test --filter RunDelete

# Test manuel timeout (avec un workflow à timeout court)
curl -X POST http://localhost:8000/api/runs \
  -H "Content-Type: application/json" \
  -d '{"workflowFile":"example.yaml","brief":"Test timeout"}' | python3 -m json.tool

# Test manuel annulation (requiert 2 terminaux : 1 pour le POST en cours, 1 pour le DELETE)
# → Pas testable manuellement en mode synchrone — utiliser les tests unitaires

# Test DELETE run inexistant
curl -X DELETE http://localhost:8000/api/runs/nonexistent-uuid | python3 -m json.tool
# Expected: HTTP 404 { "message": "...", "code": "RUN_NOT_FOUND" }
```

### §Scope délimité — Ce qui n'appartient PAS à cette story

- **Kill mid-agent-execution** → Story 2.4 (SSE long-running request + `connection_aborted()`)
- **Persistance du run sur disque** → Story 2.3 (`ArtifactService`, `session.md`)
- **SSE / événements temps réel** → Story 2.4
- **Frontend `runStore` — état `cancelled`** → Story 2.5 + 2.7a (Zustand + LaunchBar)
- **Retry automatique (mandatory)** → Story 3.2 (Epic 3)
- **Retry manuel depuis checkpoint** → Story 3.4

---

### References

- [Source: docs/planning-artifacts/epics.md#Story-2.2] — user story, AC, FR11/FR13/FR26
- [Source: docs/planning-artifacts/epics.md#NFR] — NFR2, NFR4, NFR10
- [Source: docs/planning-artifacts/architecture.md#Process-Patterns] — DriverInterface::kill(), finally block, ProcessTimedOutException
- [Source: docs/planning-artifacts/architecture.md#Gap-Critique] — Annulation via connection_aborted() (Story 2.4)
- [Source: backend/app/Drivers/DriverInterface.php] — signature actuelle à étendre
- [Source: backend/app/Services/RunService.php] — implémentation actuelle à étendre
- [Source: backend/app/Http/Controllers/RunController.php] — store() existant, destroy() à ajouter
- [Source: backend/config/xu-workflow.php] — default_timeout = 120s
- [Source: workflows/example.yaml] — timeout: 60 déjà dans le YAML d'exemple
- [Source: docs/implementation-artifacts/2-1-*.md#Review-Findings] — patterns établis, CliExecutionException re-throw pattern

## Dev Agent Record

### Agent Model Used

claude-sonnet-4-6

### Debug Log References

- `ProcessTimedOutException` requiert 2 args au constructeur — mocks Symfony + ProcessResult nécessaires dans les tests unitaires
- `Str::createUuidsUsing()` doit retourner `Ramsey\Uuid\UuidInterface`, pas une string — corrigé avec `RamseyUuid::fromString()`

### Completion Notes List

- `DriverInterface` : signature `execute()` étendue avec `int $timeout` ; `kill(int $pid): void` ajouté
- `ClaudeDriver` + `GeminiDriver` : `$timeout` passé via paramètre au lieu de config() hardcodé ; `kill()` implémenté avec `posix_kill()` + guard `function_exists`
- `AgentTimeoutException` créée → HTTP 504 `{ code: "AGENT_TIMEOUT" }`
- `RunCancelledException` créée → HTTP 409 `{ code: "RUN_CANCELLED" }`
- `RunService` : registre cache actif run + timeout par agent depuis YAML + vérification flag annulation avant chaque agent + finally cleanup (NFR4)
- `RunController::destroy()` : HTTP 404 si run inconnu, HTTP 202 + flag cancelled sinon
- Route `DELETE /api/runs/{id}` ajoutée
- `bootstrap/app.php` : 2 nouveaux renderers (AgentTimeoutException, RunCancelledException)
- `RunServiceTest` mis à jour : `->with()` sur execute() ajuste le 4e param ($this->anything())
- **Tests : 40/40 ✅** — 9 unitaires RunServiceTest (existants) + 8 unitaires RunServiceTimeoutTest (nouveaux) + 5 feature RunApiTest (existants) + 4 feature RunDeleteApiTest (nouveaux) + 12 WorkflowControllerTest + 2 exemples — 0 régression

### File List

- backend/app/Drivers/DriverInterface.php (modifié — `int $timeout` dans execute(), `kill()` ajouté)
- backend/app/Drivers/ClaudeDriver.php (modifié — `$timeout` param, `kill()` implémenté, config() supprimé)
- backend/app/Drivers/GeminiDriver.php (modifié — idem ClaudeDriver)
- backend/app/Exceptions/AgentTimeoutException.php (nouveau)
- backend/app/Exceptions/RunCancelledException.php (nouveau)
- backend/app/Services/RunService.php (modifié — registre cache, timeout par agent, annulation inter-agents, finally)
- backend/app/Http/Controllers/RunController.php (modifié — destroy() ajouté)
- backend/bootstrap/app.php (modifié — 2 nouveaux renderers d'exception)
- backend/routes/api.php (modifié — DELETE /runs/{id})
- backend/tests/Unit/RunServiceTest.php (modifié — ->with() mis à jour pour signature à 4 params)
- backend/tests/Unit/RunServiceTimeoutTest.php (nouveau — 8 tests unitaires)
- backend/tests/Feature/RunDeleteApiTest.php (nouveau — 4 tests feature)
