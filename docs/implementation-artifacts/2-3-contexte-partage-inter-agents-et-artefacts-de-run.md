# Story 2.3 : Contexte partagé inter-agents et artefacts de run

Status: done
Epic: 2
Story: 3
Date: 2026-04-06

## Story

As a développeur,
I want que chaque agent reçoive le contexte partagé et que les artefacts soient créés au fil du run,
so that chaque agent dispose de l'information nécessaire et le run est traçable.

## Acceptance Criteria

1. **Given** un run démarré — **When** le dossier run est créé — **Then** `ArtifactService` crée `runs/YYYY-MM-DD-HHmm/session.md`, `checkpoint.json` et le dossier `agents/` (FR28)
2. **Given** un agent complété — **Then** le fichier `session.md` est enrichi en mode append-only avec l'output de l'agent (FR29)
3. **Given** le contexte cycle mis à jour après chaque agent — **Then** l'agent suivant reçoit le contenu courant de `session.md` comme `$context` en entrée, en plus de son brief de tâche (FR15)
4. **Given** un agent complété — **Then** l'output brut est sauvegardé dans `agents/{agent-id}.md` (FR30)
5. **Given** tout contenu écrit sur disque — **Then** les valeurs des variables d'environnement correspondant à des credentials sont redactées (`[REDACTED]`) avant écriture — aucun credential logué (NFR12)
6. **Given** la réponse `POST /api/runs` — **Then** `RunResource` inclut `runFolder` (chemin relatif du dossier run créé)

## Tasks / Subtasks

- [x] **T1 — Créer `ArtifactService`** (AC 1, 2, 4, 5)
  - [x] Créer `backend/app/Services/ArtifactService.php`
  - [x] Méthode `initializeRun(string $runId, string $workflowFile, string $brief): string` → crée le dossier run, les fichiers initiaux, retourne le chemin absolu du dossier
  - [x] Méthode `appendAgentOutput(string $runPath, string $agentId, string $output): void` → append à `session.md` + sauvegarde `agents/{agentId}.md`
  - [x] Méthode `writeCheckpoint(string $runPath, array $data): void` → sérialise et écrit `checkpoint.json`
  - [x] Méthode `getContextContent(string $runPath): string` → lit et retourne le contenu de `session.md`
  - [x] Méthode privée `sanitizeEnvCredentials(string $content): string` → redacte les valeurs d'env credentials (AC 5)

- [x] **T2 — Mettre à jour `RunService`** (AC 2, 3, 6)
  - [x] Injecter `ArtifactService` via le constructeur
  - [x] Au début de `execute()` : appeler `ArtifactService::initializeRun()`, stocker le `$runPath`
  - [x] Avant le premier agent : initialiser `$context` = `ArtifactService::getContextContent($runPath)` (session.md initial, contient le brief en header)
  - [x] Après chaque agent réussi : appeler `ArtifactService::appendAgentOutput($runPath, $agentId, $rawOutput)`
  - [x] Mettre à jour `$context` = `ArtifactService::getContextContent($runPath)` pour le prochain agent
  - [x] Écrire le checkpoint avec `ArtifactService::writeCheckpoint()` : avant chaque agent (currentAgent, currentStep) et après (completedAgents mis à jour)
  - [x] Ajouter `runFolder` dans le tableau de retour de `execute()`

- [x] **T3 — Mettre à jour `RunResource`** (AC 6)
  - [x] Ajouter `'runFolder' => $this->resource['runFolder']` dans `toArray()`

- [x] **T4 — Tests unitaires `ArtifactService`** (couverture AC 1–5)
  - [x] Créer `backend/tests/Unit/ArtifactServiceTest.php`
  - [x] Tester `initializeRun()` : crée `session.md`, `checkpoint.json`, dossier `agents/` au bon chemin
  - [x] Tester `appendAgentOutput()` : session.md contient le contenu en append, `agents/{id}.md` créé
  - [x] Tester `sanitizeEnvCredentials()` : une valeur credential présente dans le contenu → `[REDACTED]`
  - [x] Tester `sanitizeEnvCredentials()` : une valeur courte ou générique (< 8 chars, pas pattern credential) → non redactée
  - [x] Utiliser un répertoire temporaire (`sys_get_temp_dir()`) et nettoyer dans `tearDown()`

- [x] **T5 — Mettre à jour `RunServiceTest`** (régressions)
  - [x] Ajouter mock de `ArtifactService` dans le constructeur de `RunService`
  - [x] Vérifier que `initializeRun()` est appelé une fois par run
  - [x] Vérifier que `appendAgentOutput()` est appelé autant de fois qu'il y a d'agents
  - [x] S'assurer que les 9 tests existants passent toujours

- [x] **T6 — Régresser tous les tests**
  - [x] `cd backend && php artisan test` — tous les tests verts (56/56, 0 régression)

### Review Findings (2026-04-06)

- [x] [Review][Patch] Header `session.md` écrit sans `sanitizeEnvCredentials` — violation NFR12 [ArtifactService.php:27]
- [x] [Review][Patch] Collision de timestamp — deux runs dans la même minute partagent le même `$runPath` [ArtifactService.php:17]
- [x] [Review][Patch] Cache entry fuit si `initializeRun()` lève une exception — le bloc `finally` ne couvre pas la phase d'init [RunService.php]
- [x] [Review][Patch] Tests feature créent de vrais dossiers sur le filesystem — `ArtifactService` non mocké dans `RunApiTest` [tests/Feature/RunApiTest.php]
- [x] [Review][Defer] `$agentId` utilisé directement comme nom de fichier sans validation — traversée de chemin possible [ArtifactService.php:54] — deferred, localhost single-user, agentId vient du YAML auteur
- [x] [Review][Defer] `session.md` croît sans limite et est passé intégralement comme `$context` au driver — risque de dépassement des limites CLI sur longs workflows [ArtifactService::getContextContent] — deferred, design MVP séquentiel, hors scope story 2.3
- [x] [Review][Defer] `sanitizeEnvCredentials` faux positifs : valeur longue dans une var credential-nommée (ex: `DB_PASSWORD=localhost`) redacte des mots courants dans l'output [ArtifactService.php:84] — deferred, limitation connue heuristique, acceptable MVP
- [x] [Review][Defer] `sanitizeEnvCredentials` peut manquer les credentials Unicode-escapés dans JSON — `str_replace` sur la string brute ne matche pas les séquences `\uXXXX` [ArtifactService.php:84] — deferred, cas edge très marginal
- [x] [Review][Defer] `$brief` avec newlines peut injecter de faux headers Markdown `## Agent:` dans `session.md` [ArtifactService.php:22] — deferred, localhost single-user, session.md non servi
- [x] [Review][Defer] Chemin `/session.md` dupliqué en string dans `ArtifactService` et `RunService` — renommage silencieux [ArtifactService.php, RunService.php:58] — deferred, refactoring cosmétique, faible impact
- [x] [Review][Defer] Ordre de fusion `$_ENV` + `getenv()` non documenté — valeur `getenv()` peut écraser `$_ENV` pour la même clé [ArtifactService.php:86] — deferred, les deux sources sont vérifiées, acceptable MVP

## Dev Notes

### §Architecture — `ArtifactService` : classe concrète, pas d'interface

`ArtifactService` est une classe concrète Laravel auto-résolue par le container. **Pas d'interface**, pas de binding dans `AppServiceProvider` — Laravel résout la dépendance directement par son type-hint dans le constructeur de `RunService`.

```php
// AppServiceProvider.php — AUCUN changement requis
// RunService.php — injecter directement :
public function __construct(
    private readonly DriverInterface $driver,
    private readonly YamlService $yamlService,
    private readonly ArtifactService $artifactService,  // ← ajouter
) {}
```

### §Chemin du dossier run

Le chemin de base vient de `config('xu-workflow.runs_path')` = `base_path('../runs')` (défini dans `config/xu-workflow.php`).

Format du dossier : `YYYY-MM-DD-HHmm` → `now()->format('Y-m-d-Hi')` (ex : `2026-04-06-1430`)

```php
public function initializeRun(string $runId, string $workflowFile, string $brief): string
{
    $folderName = now()->format('Y-m-d-Hi');
    $runPath = config('xu-workflow.runs_path') . '/' . $folderName;

    File::makeDirectory($runPath . '/agents', 0755, true, true);  // true = recursive, true = force (exists ok)

    $header = "# Run: {$runId}\n"
        . "# Workflow: {$workflowFile}\n"
        . "# Brief: {$brief}\n"
        . "# Started: " . now()->toIso8601String() . "\n\n";

    File::put($runPath . '/session.md', $header);

    $this->writeCheckpoint($runPath, [
        'runId'            => $runId,
        'workflowFile'     => $workflowFile,
        'brief'            => $brief,
        'completedAgents'  => [],
        'currentAgent'     => null,
        'currentStep'      => 0,
        'context'          => $runPath . '/session.md',
    ]);

    return $runPath;
}
```

Use: `use Illuminate\Support\Facades\File;`

### §Schéma exact de `checkpoint.json`

```json
{
  "runId": "uuid",
  "workflowFile": "feature-dev.yaml",
  "brief": "...",
  "completedAgents": ["pm", "laravel-dev"],
  "currentAgent": "qa",
  "currentStep": 0,
  "context": "/absolute/path/to/runs/2026-04-06-1430/session.md"
}
```

Le champ `currentStep` reste à `0` pour story 2.3 — la granularité step-level arrivera en Epic 3 (checkpoints + retry).

### §Cycle de mise à jour du contexte dans `RunService`

**CHANGEMENT CRITIQUE** par rapport à story 2.1/2.2 : le `$context` passé au driver n'est plus un JSON mais le **contenu textuel de `session.md`**.

```php
// Avant story 2.3 (supprimer ce pattern) :
$context = json_encode(['brief' => $brief], JSON_THROW_ON_ERROR);
// ... après agent :
$context = json_encode($decoded, JSON_THROW_ON_ERROR);

// Après story 2.3 :
$runPath = $this->artifactService->initializeRun($runId, $workflowFile, $brief);
$context = $this->artifactService->getContextContent($runPath);  // lit session.md

foreach ($workflow['agents'] as $agent) {
    $agentId = $agent['id'];

    // Checkpoint avant spawn : currentAgent mis à jour
    $this->artifactService->writeCheckpoint($runPath, [
        // ...completedAgents existants, currentAgent = $agentId, etc.
    ]);

    // ... vérification annulation, timeout, driver->execute() ...

    $rawOutput = $this->driver->execute(
        $workflow['project_path'],
        $systemPrompt,
        $context,         // ← contenu textuel de session.md
        $timeout
    );

    // Validation JSON reste identique
    $decoded = $this->validateJsonOutput($agentId, $rawOutput);

    // Artefacts : append session.md + save agents/{id}.md
    $this->artifactService->appendAgentOutput($runPath, $agentId, $rawOutput);

    // Context mis à jour pour le prochain agent
    $context = $this->artifactService->getContextContent($runPath);

    // Checkpoint après agent : ajouter à completedAgents
    // ...

    $agentResults[] = ['id' => $agentId, 'status' => $decoded['status']];
}
```

### §`appendAgentOutput` — format d'append dans `session.md`

```php
public function appendAgentOutput(string $runPath, string $agentId, string $output): void
{
    $sanitized = $this->sanitizeEnvCredentials($output);

    $section = "\n---\n## Agent: {$agentId}\n{$sanitized}\n";
    File::append($runPath . '/session.md', $section);

    File::put($runPath . '/agents/' . $agentId . '.md', $sanitized);
}
```

### §Sanitisation des credentials (NFR12)

La sanitisation redacte les **valeurs** des variables d'environnement dont le **nom** correspond au pattern credentials.

```php
private function sanitizeEnvCredentials(string $content): string
{
    foreach (array_merge($_ENV, getenv()) as $key => $value) {
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
```

**Règle de déclenchement :** `strlen($value) >= 8` ET nom de var contient `key|token|secret|password|credential|api` (insensible à la casse).

⚠️ Appeler `sanitizeEnvCredentials()` dans `appendAgentOutput()` ET dans `writeCheckpoint()` si le brief ou l'output pourraient contenir des valeurs d'environnement.

### §`writeCheckpoint` — sanitisation incluse

```php
public function writeCheckpoint(string $runPath, array $data): void
{
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
    $sanitized = $this->sanitizeEnvCredentials($json);
    File::put($runPath . '/checkpoint.json', $sanitized);
}
```

### §`RunResource` — ajout de `runFolder`

```php
public function toArray($request): array
{
    return [
        'runId'     => $this->resource['runId'],
        'status'    => $this->resource['status'],
        'agents'    => $this->resource['agents'],
        'duration'  => $this->resource['duration'],
        'createdAt' => $this->resource['createdAt'],
        'runFolder' => $this->resource['runFolder'],  // ← ajouter
    ];
}
```

`runFolder` est le chemin absolu du dossier (ex : `/Users/leoelmy/Projects/xu-workflow/runs/2026-04-06-1430`). Il sera utilisé dans l'événement SSE `run.completed` (story 2.4) et la modale de fin de run (story 2.7b).

### §Fichiers à créer/modifier

```
backend/
├── app/
│   ├── Services/
│   │   ├── ArtifactService.php              ← CRÉER
│   │   └── RunService.php                   ← MODIFIER — inject ArtifactService, cycle context, runFolder
│   └── Http/
│       └── Resources/
│           └── RunResource.php              ← MODIFIER — ajouter runFolder
└── tests/
    └── Unit/
        ├── ArtifactServiceTest.php          ← CRÉER
        └── RunServiceTest.php               ← MODIFIER — mock ArtifactService
```

**Aucun autre fichier à modifier.** En particulier :
- `DriverInterface.php` — **PAS de changement** (signature `$context: string` reste identique)
- `ClaudeDriver.php` / `GeminiDriver.php` — **PAS de changement** (reçoivent le contenu textuel au lieu du JSON, transparent)
- `AppServiceProvider.php` — **PAS de changement** (ArtifactService auto-résolu)
- `bootstrap/app.php` — **PAS de changement**
- `routes/api.php` — **PAS de changement**

### §Tests — mock `ArtifactService` dans `RunServiceTest`

`ArtifactService` doit être mocké dans `RunServiceTest` pour ne pas toucher au filesystem dans les tests unitaires.

```php
// Dans RunServiceTest::setUp()
$this->mockArtifact = $this->createMock(ArtifactService::class);

// Configuration du mock pour les appels de base :
$this->mockArtifact->method('initializeRun')->willReturn('/tmp/test-run');
$this->mockArtifact->method('getContextContent')->willReturn('# context content');
$this->mockArtifact->method('appendAgentOutput')->willReturn(null);
$this->mockArtifact->method('writeCheckpoint')->willReturn(null);

// RunService instancié avec les 3 dépendances :
$this->service = new RunService($this->mockDriver, $this->mockYaml, $this->mockArtifact);
```

**Les 9 tests unitaires RunServiceTest existants doivent passer sans modification majeure** — seul le constructeur change.

### §Tests unitaires `ArtifactService` — filesystem temporaire

```php
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class ArtifactServiceTest extends TestCase
{
    private string $tmpBase;
    private ArtifactService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpBase = sys_get_temp_dir() . '/artifact-test-' . uniqid();
        config(['xu-workflow.runs_path' => $this->tmpBase]);
        $this->service = new ArtifactService();
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tmpBase)) {
            File::deleteDirectory($this->tmpBase);
        }
        parent::tearDown();
    }
}
```

### §Guardrails — Erreurs à ne pas commettre

| ❌ Interdit | ✅ Correct |
|---|---|
| Passer `json_encode($decoded)` comme `$context` au driver | Passer `ArtifactService::getContextContent($runPath)` (contenu de session.md) |
| Créer `CheckpointService` séparé dans cette story | `ArtifactService` gère les checkpoints — `CheckpointService` arrivera en Epic 3 |
| Binder `ArtifactService` dans `AppServiceProvider` | Auto-résolution Laravel — pas de binding nécessaire |
| Utiliser `mkdir()` directement | Utiliser `File::makeDirectory($path, 0755, true, true)` |
| Écrire dans `storage/app/runs/` | Écrire dans `config('xu-workflow.runs_path')` = `base_path('../runs')` |
| Oublier la sanitisation dans `writeCheckpoint()` | Sanitiser `json_encode($data)` avant `File::put()` |
| Modifier `DriverInterface` | La signature `$context: string` reste inchangée |
| Casser les 40 tests existants | Mock `ArtifactService` dans `RunServiceTest`, tests verts |
| Appeler `appendAgentOutput()` avant `validateJsonOutput()` | Valider le JSON d'abord — si invalide, l'exception lève sans écrire l'artefact |

### §Portée délimitée — Ce qui n'appartient PAS à cette story

- **SSE / événements temps réel** → Story 2.4 (le `runFolder` retourné ici sera utilisé dans `run.completed`)
- **Retry depuis checkpoint** → Story 3.4 (`CheckpointService` dédié avec lecture + reprise)
- **`CheckpointService` en tant que service séparé** → Epic 3 (uniquement si le checkpoint.json doit être lu pour reprendre)
- **Validation du `project_path` run** → Déjà en scope deferred (2.1)
- **Frontend `runStore`** → Stories 2.5 + 2.7b (Zustand stores)
- **Annulation mid-agent via kill()** → Story 2.4 (SSE long-running)

### §Vérification

```bash
# Tous les tests
cd backend && php artisan test

# Tests uniquement Story 2.3
cd backend && php artisan test --filter ArtifactService
cd backend && php artisan test --filter RunService

# Test manuel complet
curl -X POST http://localhost:8000/api/runs \
  -H "Content-Type: application/json" \
  -d '{"workflowFile":"example-feature-dev.yaml","brief":"Test story 2.3"}' | python3 -m json.tool
# Expected: { runId, status, agents, duration, createdAt, runFolder }

# Vérifier les artefacts créés
ls -la ../runs/
cat ../runs/$(ls -t ../runs | head -1)/session.md
cat ../runs/$(ls -t ../runs | head -1)/checkpoint.json
ls ../runs/$(ls -t ../runs | head -1)/agents/
```

---

### References

- [Source: docs/planning-artifacts/epics.md#Story-2.3] — user story, AC, FR14/FR15/FR28/FR29/FR30
- [Source: docs/planning-artifacts/epics.md#NonFunctional-Requirements] — NFR12 (sanitisation env)
- [Source: docs/planning-artifacts/epics.md#Additional-Requirements] — Structure dossier run, Schema checkpoint.json
- [Source: docs/planning-artifacts/architecture.md#Data-Architecture] — structure `runs/`, schéma checkpoint.json complet
- [Source: docs/planning-artifacts/architecture.md#Project-Structure] — `ArtifactService.php` dans `app/Services/`, `CheckpointService.php` pour Epic 3
- [Source: docs/planning-artifacts/architecture.md#Enforcement-Guidelines] — `sanitizeEnvCredentials` avant Storage::put()
- [Source: backend/config/xu-workflow.php] — `runs_path = base_path('../runs')`
- [Source: backend/app/Services/RunService.php] — implémentation actuelle à étendre (inject ArtifactService)
- [Source: backend/app/Http/Resources/RunResource.php] — à étendre avec `runFolder`
- [Source: docs/implementation-artifacts/2-2-timeout-par-tache-et-annulation-de-run.md#Dev-Notes] — patterns RunService, injection, finally block
- [Source: docs/implementation-artifacts/deferred-work.md] — runId non persisté résolu ici (2.1 deferred)

## Dev Agent Record

### Agent Model Used

claude-sonnet-4-6

### Debug Log References

- `willReturn(null)` interdit sur méthodes `void` en PHPUnit — supprimé des mocks `appendAgentOutput` et `writeCheckpoint`
- `getContextContent` appelé N+1 fois pour N agents (1 init + 1 après chaque agent) — test mis à jour avec `exactly(3)` et `willReturnOnConsecutiveCalls` à 3 valeurs

### Completion Notes List

- `ArtifactService` créé : `initializeRun()`, `appendAgentOutput()`, `writeCheckpoint()`, `getContextContent()`, `sanitizeEnvCredentials()` (privée)
- Structure artefacts créée : `runs/YYYY-MM-DD-HHmm/session.md` (header brief), `checkpoint.json` (schéma complet), `agents/` (vide à l'init)
- `RunService` refactoré : inject `ArtifactService`, contexte basculé de JSON vers contenu session.md, `runFolder` ajouté dans le retour
- Checkpoint écrit avant chaque agent (currentAgent) — liste completedAgents maintenue entre itérations
- `RunResource` étendu avec `runFolder`
- `RunServiceTest` : 9 tests existants + 2 nouveaux (initializeRun once, appendAgentOutput par agent), renommage du test de contexte
- `RunServiceTimeoutTest` : mock ArtifactService ajouté au constructeur — 8/8 tests maintenus
- **56/56 tests ✅** — 14 ArtifactServiceTest (nouveaux) + 11 RunServiceTest + 8 RunServiceTimeoutTest + 5 RunApiTest + 4 RunDeleteApiTest + 12 WorkflowControllerTest + 2 exemples — 0 régression

### File List

- backend/app/Services/ArtifactService.php (nouveau)
- backend/app/Services/RunService.php (modifié — inject ArtifactService, contexte session.md, checkpoint, runFolder)
- backend/app/Http/Resources/RunResource.php (modifié — runFolder ajouté)
- backend/tests/Unit/ArtifactServiceTest.php (nouveau — 14 tests)
- backend/tests/Unit/RunServiceTest.php (modifié — mock ArtifactService, 2 nouveaux tests, renommage test contexte)
- backend/tests/Unit/RunServiceTimeoutTest.php (modifié — mock ArtifactService ajouté au constructeur)
