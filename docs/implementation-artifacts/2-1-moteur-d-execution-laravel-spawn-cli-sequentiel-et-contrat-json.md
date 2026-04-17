# Story 2.1 : Moteur d'exécution Laravel — spawn CLI séquentiel et contrat JSON

Status: done
Epic: 2
Story: 1
Date: 2026-04-05

## Story

As a développeur,
I want que Laravel spawne les agents CLI séquentiellement et capture leur sortie JSON structurée,
so that le pipeline d'agents s'exécute de bout en bout sans intervention humaine.

## Acceptance Criteria

1. **Given** `POST /api/runs` avec `workflowFile` et `brief` valides — **When** la requête arrive — **Then** un `runId` UUID est généré, `RunService::execute()` est appelé, le premier agent est spawné (FR8)
2. **Given** chaque agent — **When** il est exécuté — **Then** `DriverInterface::execute()` est appelé depuis `RunService` via injection (jamais `new ClaudeDriver()`) — NFR10
3. **Given** le `ClaudeDriver` actif — **Then** la commande CLI est `claude -p --output-format json [--allowedTools "..."] [--append-system-prompt "..."]` avec stdin = contexte JSON (FR9)
4. **Given** le `GeminiDriver` actif — **Then** la commande CLI est `gemini -p --yolo [--append-system-prompt "..."]` avec stdin = contexte JSON (FR9)
5. **Given** un `system_prompt` inline ou un `system_prompt_file` dans le YAML — **Then** le system prompt est injecté via `--append-system-prompt` ; priorité : inline > fichier > vide (FR10)
6. **Given** la sortie stdout de l'agent — **When** elle est parsée — **Then** elle est validée contre le schéma `{ step, status, output, next_action, errors }` — champ manquant → `InvalidJsonOutputException` (NFR9)
7. **Given** un JSON invalide ou une sortie non-JSON — **Then** `InvalidJsonOutputException` est levée et exposée dans la réponse API (pas de crash silencieux) — NFR7
8. **Given** N agents séquentiels — **Then** la sortie JSON de l'agent N devient le contexte stdin de l'agent N+1 (FR17)
9. **Given** la réponse finale — **Then** `POST /api/runs` retourne `{ runId, status, agents[], duration, createdAt }` avec HTTP 201 — `RunResource` transforme en camelCase
10. **Given** `RunService` dans le conteneur Laravel — **Then** `DriverInterface` est résolu via `AppServiceProvider::register()` (binding interface → driver concret) — NFR10

## Tasks / Subtasks

- [x] **T1 — Redéfinir `DriverInterface::execute()` avec la bonne signature** (prérequis : AC 2, 3, 4)
  - [x] Ouvrir `backend/app/Drivers/DriverInterface.php`
  - [x] Remplacer la signature actuelle par : `execute(string $projectPath, string $systemPrompt, string $context): string`
  - [x] Mettre à jour `ClaudeDriver::execute()` avec la nouvelle signature (stub → implémentation réelle)
  - [x] Mettre à jour `GeminiDriver::execute()` avec la nouvelle signature (stub → implémentation réelle)
  - [x] Supprimer la méthode `cancel(string $jobId): void` de `DriverInterface` (non nécessaire en Story 2.1, pas en scope)

- [x] **T2 — Implémenter `ClaudeDriver::execute()`** (AC 3, 5)
  - [x] Construire la commande : `claude -p --output-format json`
  - [x] Ajouter `--allowedTools "Bash,Read,Write,Edit"` (ou liste configurable via `$options`)
  - [x] Ajouter `--append-system-prompt "{$systemPrompt}"` si `$systemPrompt` non vide
  - [x] Utiliser `Process::path($projectPath)->input($context)->run($command)`
  - [x] Vérifier `$result->exitCode()` → si ≠ 0, lancer `CliExecutionException`
  - [x] Retourner `$result->output()` (stdout brut)

- [x] **T3 — Implémenter `GeminiDriver::execute()`** (AC 4, 5)
  - [x] Construire la commande : `gemini -p --yolo`
  - [x] Ajouter `--append-system-prompt "{$systemPrompt}"` si non vide
  - [x] Même pattern `Process::path()->input()->run()` que Claude
  - [x] Retourner stdout brut

- [x] **T4 — Créer `InvalidJsonOutputException`** (AC 6, 7)
  - [x] Créer `backend/app/Exceptions/InvalidJsonOutputException.php`
  - [x] Extends `\RuntimeException`
  - [x] Constructeur : `(string $agentId, string $rawOutput, string $reason)`
  - [x] Enregistrer dans `bootstrap/app.php` → renvoyer HTTP 422 avec `{ message, code: "INVALID_JSON_OUTPUT" }`

- [x] **T5 — Créer `CliExecutionException`** (AC 3, 4)
  - [x] Créer `backend/app/Exceptions/CliExecutionException.php`
  - [x] Extends `\RuntimeException`
  - [x] Constructeur : `(string $agentId, int $exitCode, string $stderr)`
  - [x] Enregistrer dans `bootstrap/app.php` → renvoyer HTTP 500 avec `{ message, code: "CLI_EXECUTION_FAILED" }`

- [x] **T6 — Étendre `YamlService` avec `load(string $filename): array`** (prérequis : AC 1)
  - [x] Ajouter méthode `load(string $filename): array` dans `YamlService`
  - [x] Chercher dans `config('xu-maestro.workflows_path') . '/' . $filename`
  - [x] Parser avec `Yaml::parseFile()` + appeler `$this->validate($data)`
  - [x] Lever `\InvalidArgumentException` si fichier introuvable ou YAML invalide
  - [x] Étendre `validate()` pour vérifier `project_path` (string non vide) — nécessaire pour NFR11

- [x] **T7 — Créer `RunService`** (AC 1, 6, 7, 8)
  - [x] Créer `backend/app/Services/RunService.php`
  - [x] Constructeur injecte `DriverInterface $driver`
  - [x] Méthode `execute(string $workflowFile, string $brief): array`
    - [x] Appeler `YamlService::load($workflowFile)` pour charger le workflow
    - [x] Générer `$runId = Str::uuid()->toString()`
    - [x] Initialiser `$context = json_encode(['brief' => $brief])`
    - [x] Boucler sur `$workflow['agents']` séquentiellement :
      - [x] Résoudre `$systemPrompt` (inline → fichier → vide)
      - [x] Appeler `$this->driver->execute($workflow['project_path'], $systemPrompt, $context)`
      - [x] Appeler `$this->validateJsonOutput($agentId, $rawOutput)`
      - [x] `$context = $rawOutput` pour l'agent suivant
    - [x] Retourner `['runId' => $runId, 'status' => 'completed', 'agents' => [...], 'duration' => $ms, 'createdAt' => now()->toIso8601String()]`
  - [x] Méthode privée `validateJsonOutput(string $agentId, string $rawOutput): array`
    - [x] `json_decode($rawOutput, true)` — si `null` → `InvalidJsonOutputException`
    - [x] Vérifier présence de `step`, `status`, `output`, `next_action`, `errors` — champ manquant → `InvalidJsonOutputException`
    - [x] Retourner le tableau décodé
  - [x] Méthode privée `resolveSystemPrompt(array $agent): string`
    - [x] Si `$agent['system_prompt']` → retourner tel quel
    - [x] Sinon si `$agent['system_prompt_file']` → lire `config('xu-maestro.prompts_path') . '/' . $filename`
    - [x] Sinon → retourner `''`

- [x] **T8 — Créer `RunResource`** (AC 9)
  - [x] Créer `backend/app/Http/Resources/RunResource.php`
  - [x] Retourner : `{ runId, status, agents: [{id, status}], duration, createdAt }`
  - [x] Transformation camelCase (même pattern que `WorkflowResource`)

- [x] **T9 — Créer `RunController`** (AC 1, 9)
  - [x] Créer `backend/app/Http/Controllers/RunController.php`
  - [x] Méthode `store(Request $request)`
  - [x] Valider : `workflowFile` (string, required), `brief` (string, required)
  - [x] Appeler `RunService::execute()` injecté dans le constructeur
  - [x] Retourner `new RunResource($result)` avec HTTP 201

- [x] **T10 — Enregistrer la route et le binding DI** (AC 10)
  - [x] Ajouter dans `backend/routes/api.php` : `Route::post('/runs', [RunController::class, 'store'])`
  - [x] Dans `AppServiceProvider::register()` : `$this->app->bind(DriverInterface::class, ClaudeDriver::class)`
  - [x] Ajouter le use namespace pour `DriverInterface` et `ClaudeDriver`

- [x] **T11 — Tests unitaires `RunServiceTest`** (couverture complète)
  - [x] Créer `backend/tests/Unit/RunServiceTest.php`
  - [x] Mocker `DriverInterface` via `$this->createMock(DriverInterface::class)`
  - [x] Tester : YAML valide, 1 agent, JSON valide → retourne runId + status "completed"
  - [x] Tester : 2 agents → sortie agent 1 devient contexte de l'agent 2 (vérifier `execute` appelé 2x)
  - [x] Tester : sortie non-JSON → `InvalidJsonOutputException`
  - [x] Tester : champ manquant dans JSON → `InvalidJsonOutputException`
  - [x] Tester : `system_prompt` inline → injecté dans `execute()`
  - [x] Tester : `system_prompt_file` → contenu du fichier injecté dans `execute()`
  - [x] Tester : ni `system_prompt` ni fichier → `''` injecté

- [x] **T12 — Tests feature `RunApiTest`**
  - [x] Créer `backend/tests/Feature/RunApiTest.php`
  - [x] Mocker `DriverInterface` dans le conteneur DI (`$this->mock(DriverInterface::class, ...)`)
  - [x] Tester : `POST /api/runs` → HTTP 201 + structure `{ runId, status, agents, duration, createdAt }`
  - [x] Tester : YAML inexistant → HTTP 422
  - [x] Tester : JSON invalide retourné par driver → HTTP 422 + `code: "INVALID_JSON_OUTPUT"`

### Review Findings (2026-04-05)

- [x] [Review][Patch] Traversal via `system_prompt_file` — `basename()` appliqué sur `$agent['system_prompt_file']` dans `resolveSystemPrompt()`. [RunService.php]
- [x] [Review][Dismiss] Run `status` toujours `"completed"` — comportement correct : "completed" = exécution sans exception. Le statut des agents dans `agents[]` reflète leurs statuts individuels. Pas de changement.
- [x] [Review][Patch] Context chaining : `json_encode($decoded)` au lieu de `$rawOutput` — JSON validé et re-sérialisé, contexte propre garanti. [RunService.php]
- [x] [Review][Patch] Path traversal via `workflowFile` — `basename()` dans `YamlService::load()` + `regex:/^[\w\-]+\.ya?ml$/` dans `RunController`. [RunController.php + YamlService.php]
- [x] [Review][Patch] `\InvalidArgumentException` renderer trop large — `YamlLoadException extends \InvalidArgumentException` créée; `YamlService` l'utilise; `bootstrap/app.php` catcher uniquement `YamlLoadException`. [bootstrap/app.php + YamlService.php]
- [x] [Review][Patch] `validateJsonOutput` lève `TypeError` sur JSON scalaire — `!is_array($decoded)` ajouté après le null check. [RunService.php]
- [x] [Review][Patch] `json_encode` retourne `false` sur UTF-8 invalide — `JSON_THROW_ON_ERROR` utilisé partout. [RunService.php]
- [x] [Review][Patch] Pas de timeout sur `Process::run()` — `->timeout(config('xu-maestro.default_timeout', 120))` ajouté dans les deux drivers. [ClaudeDriver.php, GeminiDriver.php]
- [x] [Review][Patch] `resolveSystemPrompt` utilise `empty()` — remplacé par `isset() && !== ''`. [RunService.php]
- [x] [Review][Patch] `file_get_contents` échec silencieux — vérification du retour `false` ajoutée. [RunService.php]
- [x] [Review][Patch] `CliExecutionException` expose stderr brut en HTTP 500 — tronqué à 200 chars dans la réponse HTTP. [bootstrap/app.php]
- [x] [Review][Patch] `CliExecutionException` identifie l'agent par le nom du driver — `RunService` re-lance avec `$agentId` correct via try/catch. [RunService.php]
- [x] [Review][Patch] `createdAt` capturé après la fin du run — capturé avant le foreach. [RunService.php]
- [x] [Review][Defer] `cancel()` supprimé de `DriverInterface` — était un stub `throw RuntimeException('Not implemented')`, aucun appelant connu. Acceptable pour Story 2.1. [DriverInterface.php] — deferred, no callers exist
- [x] [Review][Defer] Pas d'auth/rate-limit sur `POST /runs` — concerne la couche applicative globale, hors scope Story 2.1. [routes/api.php] — deferred, app-level concern
- [x] [Review][Defer] `GeminiDriver --yolo` sans restriction d'outils — flag intentionnel per spec, Gemini n'a pas de `--allowedTools` équivalent. [GeminiDriver.php] — deferred, by design per spec
- [x] [Review][Defer] `project_path` non validé comme répertoire existant — Process lèvera une RuntimeException claire si le path est invalide. Acceptable MVP. [YamlService.php] — deferred, MVP acceptable
- [x] [Review][Defer] IDs agents dupliqués non détectés — cas limite, acceptable pour MVP sans base de données. [YamlService.php] — deferred, MVP acceptable
- [x] [Review][Defer] `RunResource extends JsonResource` pour un array — fonctionne correctement, purement cosmétique. [RunResource.php] — deferred, cosmetic
- [x] [Review][Defer] `runId` non persisté — explicitement reporté à Story 2.3 (persistence filesystem). [RunService.php] — deferred, Story 2.3
- [x] [Review][Defer] Pas de limite de taille sur stdout capturé — hors scope MVP. [ClaudeDriver.php] — deferred, MVP scope
- [x] [Review][Defer] `--allowedTools` hardcodé dans ClaudeDriver — acceptable pour MVP, liste fixe conforme à la spec. [ClaudeDriver.php] — deferred, MVP acceptable
- [x] [Review][Defer] `startedAt` inclut le temps de chargement YAML — cosmétique, acceptable. [RunService.php] — deferred, cosmetic

---

## Dev Notes

### §CRITIQUE — État réel du code existant

**`DriverInterface` a la MAUVAISE signature.** Elle doit être mise à jour :

```php
// ❌ ACTUEL (à supprimer)
public function execute(string $prompt, array $options): string;
public function cancel(string $jobId): void;

// ✅ CIBLE (Story 2.1)
public function execute(string $projectPath, string $systemPrompt, string $context): string;
```

`cancel()` est hors scope Story 2.1 — la supprimer de l'interface maintenant (Story 2.2 redéfinira le contrat de timeout/kill).

**`ClaudeDriver` et `GeminiDriver` existent déjà** dans `backend/app/Drivers/` avec stubs `throw new \RuntimeException('Not implemented')`. Mettre à jour, ne pas recréer.

**`AppServiceProvider::register()` est vide.** Le binding `DriverInterface::class → ClaudeDriver::class` doit y être ajouté.

**`YamlService::loadAll()` existe** mais pas `load(string $filename)`. La méthode `validate()` ne vérifie pas `project_path`. Les deux sont à étendre.

**`config/xu-maestro.php`** a déjà `runs_path` et `prompts_path` définis. Ne pas les redéfinir.

---

### §Architecture obligatoire — Fichiers à créer/modifier

```
backend/
├── app/
│   ├── Drivers/
│   │   ├── DriverInterface.php          ← MODIFIER signature execute(), supprimer cancel()
│   │   ├── ClaudeDriver.php             ← MODIFIER — implémenter execute()
│   │   └── GeminiDriver.php             ← MODIFIER — implémenter execute()
│   ├── Exceptions/
│   │   ├── InvalidJsonOutputException.php  ← CRÉER
│   │   └── CliExecutionException.php       ← CRÉER
│   ├── Http/
│   │   ├── Controllers/
│   │   │   └── RunController.php        ← CRÉER
│   │   └── Resources/
│   │       └── RunResource.php          ← CRÉER
│   ├── Providers/
│   │   └── AppServiceProvider.php       ← MODIFIER — binding DI
│   └── Services/
│       ├── YamlService.php              ← MODIFIER — ajouter load() + valider project_path
│       └── RunService.php               ← CRÉER
├── routes/
│   └── api.php                          ← MODIFIER — ajouter POST /runs
└── tests/
    ├── Feature/
    │   └── RunApiTest.php               ← CRÉER
    └── Unit/
        └── RunServiceTest.php           ← CRÉER
```

---

### §Process Facade — Usage correct

```php
use Illuminate\Support\Facades\Process;

// Dans ClaudeDriver::execute()
$result = Process::path($projectPath)
    ->input($context)
    ->run($command);

if ($result->failed()) {
    throw new CliExecutionException($agentId, $result->exitCode(), $result->errorOutput());
}

return $result->output();
```

**`Process::path()` configure le `cwd` du processus** — obligatoire pour NFR11. Ne jamais `exec()` ou `shell_exec()` directement.

**`->input($context)`** passe le contexte JSON en stdin du CLI. Format : JSON string.

---

### §Commandes CLI exactes

**Claude :**
```bash
claude -p --output-format json --allowedTools "Bash,Read,Write,Edit" --append-system-prompt "SYSTEM_PROMPT_ICI"
```

**Gemini :**
```bash
gemini -p --yolo --append-system-prompt "SYSTEM_PROMPT_ICI"
```

- `-p` = prompt depuis stdin
- `--append-system-prompt` absent si systemPrompt est vide (ne pas injecter flag vide)
- `--allowedTools` pour Claude uniquement (Gemini utilise `--yolo` pour tout autoriser)

---

### §Contrat JSON de sortie agent

Chaque agent CLI doit retourner un JSON structuré en stdout :

```json
{
  "step": "analyse-brief",
  "status": "done",
  "output": "Brief analysé : notifications in-app avec badge...",
  "next_action": "implement-feature",
  "errors": []
}
```

Tous les champs sont **obligatoires**. Validation stricte dans `validateJsonOutput()` :

```php
private function validateJsonOutput(string $agentId, string $rawOutput): array
{
    $decoded = json_decode($rawOutput, true);
    if ($decoded === null) {
        throw new InvalidJsonOutputException($agentId, $rawOutput, 'Not valid JSON');
    }

    $required = ['step', 'status', 'output', 'next_action', 'errors'];
    foreach ($required as $field) {
        if (!array_key_exists($field, $decoded)) {
            throw new InvalidJsonOutputException($agentId, $rawOutput, "Missing field: {$field}");
        }
    }

    return $decoded;
}
```

---

### §Résolution du system prompt

Priorité dans `resolveSystemPrompt(array $agent): string` :

```php
private function resolveSystemPrompt(array $agent): string
{
    // Priorité 1 : inline
    if (!empty($agent['system_prompt'])) {
        return $agent['system_prompt'];
    }

    // Priorité 2 : fichier externe
    if (!empty($agent['system_prompt_file'])) {
        $path = config('xu-maestro.prompts_path') . '/' . $agent['system_prompt_file'];
        if (file_exists($path)) {
            return file_get_contents($path);
        }
    }

    // Priorité 3 : vide
    return '';
}
```

Le YAML `example.yaml` utilise `system_prompt: "Tu es un agent de démonstration."` — format inline.

---

### §YamlService::load() — Implémentation exacte

```php
public function load(string $filename): array
{
    $path = config('xu-maestro.workflows_path') . '/' . $filename;

    if (!file_exists($path)) {
        throw new \InvalidArgumentException("Workflow file not found: {$filename}");
    }

    try {
        $data = Yaml::parseFile($path);
    } catch (ParseException $e) {
        throw new \InvalidArgumentException("Invalid YAML in {$filename}: " . $e->getMessage());
    }

    if (!$this->validate($data)) {
        throw new \InvalidArgumentException("Invalid workflow structure in {$filename}");
    }

    $data['file'] = $filename;
    return $data;
}
```

Et dans `validate()`, ajouter la vérification de `project_path` :

```php
// Après la validation des agents :
if (!isset($data['project_path']) || !is_string($data['project_path']) || $data['project_path'] === '') {
    return false;
}
```

---

### §RunResource — Implémentation exacte

```php
// backend/app/Http/Resources/RunResource.php
public function toArray($request): array
{
    return [
        'runId'     => $this->resource['runId'],
        'status'    => $this->resource['status'],
        'agents'    => $this->resource['agents'],
        'duration'  => $this->resource['duration'],
        'createdAt' => $this->resource['createdAt'],
    ];
}
```

`JsonResource::withoutWrapping()` déjà configuré dans `AppServiceProvider::boot()` — pas de wrapper `{ data: ... }`.

---

### §AppServiceProvider — Binding DI

```php
// backend/app/Providers/AppServiceProvider.php
use App\Drivers\ClaudeDriver;
use App\Drivers\DriverInterface;

public function register(): void
{
    $this->app->bind(DriverInterface::class, ClaudeDriver::class);
}
```

**Jamais instancier directement dans `RunService` :**
```php
// ❌ INTERDIT
$driver = new ClaudeDriver();

// ✅ CORRECT — injection via constructeur
public function __construct(private readonly DriverInterface $driver) {}
```

---

### §Exception Handling Laravel 11

Dans Laravel 11, les exceptions sont enregistrées dans `bootstrap/app.php` :

```php
// bootstrap/app.php
->withExceptions(function (Exceptions $exceptions) {
    $exceptions->render(function (InvalidJsonOutputException $e, Request $request) {
        return response()->json([
            'message' => $e->getMessage(),
            'code'    => 'INVALID_JSON_OUTPUT',
        ], 422);
    });

    $exceptions->render(function (CliExecutionException $e, Request $request) {
        return response()->json([
            'message' => $e->getMessage(),
            'code'    => 'CLI_EXECUTION_FAILED',
        ], 500);
    });

    $exceptions->render(function (\InvalidArgumentException $e, Request $request) {
        return response()->json([
            'message' => $e->getMessage(),
            'code'    => 'YAML_INVALID',
        ], 422);
    });
})
```

---

### §Tests — Pattern de mock DI

```php
// Feature test — mocker dans le conteneur Laravel
public function test_post_runs_returns_201(): void
{
    $mockDriver = $this->createMock(DriverInterface::class);
    $mockDriver->method('execute')->willReturn(json_encode([
        'step' => 'test', 'status' => 'done', 'output' => 'ok',
        'next_action' => null, 'errors' => []
    ]));

    $this->app->instance(DriverInterface::class, $mockDriver);

    $response = $this->postJson('/api/runs', [
        'workflowFile' => 'example.yaml',
        'brief' => 'Test brief',
    ]);

    $response->assertStatus(201)
        ->assertJsonStructure(['runId', 'status', 'agents', 'duration', 'createdAt']);
}
```

```php
// Unit test — injection directe du mock
public function test_invalid_json_throws_exception(): void
{
    $mockDriver = $this->createMock(DriverInterface::class);
    $mockDriver->method('execute')->willReturn('not-json');

    $yamlService = $this->createMock(YamlService::class);
    $yamlService->method('load')->willReturn([
        'name' => 'Test', 'project_path' => '/tmp',
        'agents' => [['id' => 'agent-1', 'engine' => 'claude-code']],
        'file' => 'test.yaml',
    ]);

    $service = new RunService($mockDriver, $yamlService);

    $this->expectException(InvalidJsonOutputException::class);
    $service->execute('test.yaml', 'brief');
}
```

---

### §Guardrails — Erreurs à ne pas commettre

| ❌ Interdit | ✅ Correct |
|---|---|
| `$driver = new ClaudeDriver()` dans RunService | Injecter `DriverInterface` via constructeur |
| Ignorer le JSON invalide | Lever `InvalidJsonOutputException` toujours |
| `exec()` ou `shell_exec()` | `Process::path()->input()->run()` uniquement |
| Spawn sans `project_path` | `Process::path($projectPath)` obligatoire (NFR11) |
| Implémenter timeout/kill | Hors scope — Story 2.2 |
| Persister run sur disque | Hors scope — Story 2.3 |
| Implémentation SSE | Hors scope — Story 2.4 |
| Exécution parallèle agents | Séquentiel uniquement (MVP) |
| Loguer `system_prompt` si contient API keys | Ne pas loguer les prompts en clair |
| Recréer `DriverInterface` ou `ClaudeDriver` | Ils existent — modifier seulement |
| `cancel()` dans l'interface | Supprimer de `DriverInterface` (Story 2.2 redéfinit) |
| Wrapper `{ data: ... }` dans RunResource | `withoutWrapping()` déjà actif dans AppServiceProvider |

---

### §Scope délimité — Ce qui n'appartient PAS à cette story

- **Timeout/kill** → Story 2.2
- **Persistance `runs/` sur disque, `session.md`** → Story 2.3
- **SSE / événements temps réel** → Story 2.4
- **Frontend `runStore`, LaunchBar** → Stories 2.5, 2.7a
- **Sanitisation des env vars** → Story 2.3 (NFR12)

---

### §API Specification complète

**Endpoint :** `POST /api/runs`

**Request body :**
```json
{
  "workflowFile": "example.yaml",
  "brief": "Ajouter notifications in-app avec badge et dropdown"
}
```

**Success 201 :**
```json
{
  "runId": "550e8400-e29b-41d4-a716-446655440000",
  "status": "completed",
  "agents": [
    { "id": "agent-one", "status": "done" }
  ],
  "duration": 1245,
  "createdAt": "2026-04-05T10:30:00Z"
}
```

**Error 422 — JSON invalide :**
```json
{
  "message": "Agent 'agent-one' returned invalid JSON: Missing field: step",
  "code": "INVALID_JSON_OUTPUT"
}
```

**Error 422 — YAML invalide/introuvable :**
```json
{
  "message": "Workflow file not found: unknown.yaml",
  "code": "YAML_INVALID"
}
```

---

### §Apprentissages stories précédentes applicables

**Story 1.3 (YamlService) :**
- `YamlService` est dans `App\Services` — injection via constructeur fonctionne
- `config('xu-maestro.workflows_path')` = `base_path('../workflows')` (résolu = `{repo-root}/workflows/`)
- `WorkflowResource` fait la transformation camelCase — même pattern pour `RunResource`
- Tests feature : `WorkflowControllerTest` utilise `$this->getJson('/api/workflows')` — même pattern

**Story 1.4 (Zustand) :**
- `next lint` n'existe pas dans Next.js 16.2.2 — pour PHP utiliser `./vendor/bin/phpstan` si disponible

**Story 1.5 (Review findings) :**
- Card shadcn utilise `ring-1` pas `border` — pas applicable ici (backend story)
- Pattern injection constructor `readonly` bien établi

**Commits récents confirmés :**
- `feat: 1.5` (2026-04-04) — React Flow, AgentCard, DiagramEdge créés
- `feat: 1.4`, `1.3` — patterns Zustand + YamlService en place

---

### §Vérification

```bash
# Backend
cd backend && php artisan serve   # port 8000

# Test manuel
curl -X POST http://localhost:8000/api/runs \
  -H "Content-Type: application/json" \
  -d '{"workflowFile":"example.yaml","brief":"Test brief"}' | python3 -m json.tool

# Tests unitaires
cd backend && php artisan test --filter RunServiceTest

# Tests feature
cd backend && php artisan test --filter RunApiTest

# Tous les tests (ne pas casser les 14 tests existants WorkflowControllerTest)
cd backend && php artisan test
```

---

### References

- [Source: docs/planning-artifacts/epics.md#Epic-2-Story-2.1] — user story, AC, FR8/FR9/FR10/FR16/FR17
- [Source: docs/planning-artifacts/architecture.md#Backend] — DriverInterface, RunService, patterns
- [Source: docs/planning-artifacts/epics.md#NFR] — NFR2, NFR7, NFR8, NFR9, NFR10, NFR11
- [Source: backend/app/Drivers/DriverInterface.php] — signature actuelle à corriger
- [Source: backend/app/Services/YamlService.php] — méthodes existantes à étendre
- [Source: backend/app/Providers/AppServiceProvider.php] — binding DI à ajouter
- [Source: backend/config/xu-maestro.php] — runs_path et prompts_path déjà définis
- [Source: docs/implementation-artifacts/1-5-*.md] — patterns de code établis, learnings

## Dev Agent Record

### Agent Model Used

claude-sonnet-4-6

### Completion Notes List

- `DriverInterface` : signature remplacée par `execute(string $projectPath, string $systemPrompt, string $context): string` ; `cancel()` supprimé
- `ClaudeDriver` : implémenté avec `Process::path()->input()->run()`, commande `claude -p --output-format json --allowedTools "Bash,Read,Write,Edit" [--append-system-prompt ...]`
- `GeminiDriver` : implémenté avec `Process::path()->input()->run()`, commande `gemini -p --yolo [--append-system-prompt ...]`
- `InvalidJsonOutputException` et `CliExecutionException` créées dans `app/Exceptions/`
- `bootstrap/app.php` : 3 renderers d'exception enregistrés (422/422/500)
- `YamlService` : méthode `load(string $filename)` ajoutée ; `validate()` étendue pour exiger `project_path`
- `RunService` : exécution séquentielle, contexte agent-to-agent, validation JSON stricte, system prompt 3 priorités
- `RunResource` : camelCase, sans wrapper `data` (withoutWrapping déjà actif)
- `RunController` : validation request, injection RunService, HTTP 201
- Route `POST /api/runs` ajoutée dans `api.php`
- Binding DI `DriverInterface → ClaudeDriver` dans `AppServiceProvider::register()`
- **Tests : 28/28 ✅** — 9 unitaires (RunServiceTest) + 5 feature (RunApiTest) + 14 existants (WorkflowControllerTest) — 0 régression

### Change Log

- 2026-04-05 : Story 2.1 créée — moteur d'exécution Laravel
- 2026-04-05 : Story 2.1 implémentée — DriverInterface corrigé, drivers Claude/Gemini, RunService/Controller/Resource, exceptions, tests 28/28
- 2026-04-05 : Code review — 12 patches appliqués (path traversal x2, YamlLoadException, TypeError fix, JSON_THROW_ON_ERROR, timeout drivers, empty() fix, file_get_contents check, stderr truncation, agentId fix, createdAt, context chaining)

### File List

- backend/app/Drivers/DriverInterface.php (modifié — nouvelle signature execute(), cancel() supprimé)
- backend/app/Drivers/ClaudeDriver.php (modifié — implémentation réelle Process Facade)
- backend/app/Drivers/GeminiDriver.php (modifié — implémentation réelle Process Facade)
- backend/app/Exceptions/InvalidJsonOutputException.php (nouveau)
- backend/app/Exceptions/CliExecutionException.php (nouveau)
- backend/app/Services/YamlService.php (modifié — load() ajouté, validate() + project_path)
- backend/app/Services/RunService.php (nouveau)
- backend/app/Http/Resources/RunResource.php (nouveau)
- backend/app/Http/Controllers/RunController.php (nouveau)
- backend/app/Providers/AppServiceProvider.php (modifié — binding DI DriverInterface→ClaudeDriver)
- backend/routes/api.php (modifié — POST /runs ajouté)
- backend/bootstrap/app.php (modifié — 3 exception renderers)
- backend/tests/Unit/RunServiceTest.php (nouveau — 9 tests unitaires)
- backend/tests/Feature/RunApiTest.php (nouveau — 5 tests feature)
- backend/app/Exceptions/YamlLoadException.php (nouveau — code review patch)
