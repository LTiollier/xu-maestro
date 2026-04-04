# Story 1.3 : API Laravel — chargement, validation et exposition des workflows YAML

Status: done
Epic: 1
Story: 3
Date: 2026-04-04

## Story

As a développeur,
I want une API Laravel qui charge, valide et expose les fichiers YAML du dossier `workflows/`,
so that le frontend peut récupérer la liste des workflows et leurs configurations d'agents.

## Acceptance Criteria

1. **Given** un ou plusieurs fichiers YAML valides dans `workflows/` — **When** `GET /api/workflows` est appelé — **Then** la réponse HTTP 200 retourne un tableau JSON sans wrapper `data`, chaque entrée contenant `name`, `file`, et un tableau `agents` avec `id`, `engine`, `timeout` par agent
2. **Given** les champs retournés par l'API — **When** la réponse est inspectée — **Then** tous les champs sont en camelCase (transformation dans `WorkflowResource` uniquement — jamais dans les Controllers ou Services)
3. **Given** un YAML malformé (syntaxe invalide) ou manquant d'un champ obligatoire (`name`, `agents`) — **When** `GET /api/workflows` est appelé — **Then** ce workflow est silencieusement exclu de la liste (le reste des YAML valides est retourné)
4. **Given** une validation YAML explicitement demandée — **When** le format `{ "message": "...", "code": "YAML_INVALID" }` est retourné — **Then** le status HTTP est 422
5. **Given** le dossier `workflows/` — **When** `YamlService` est utilisé — **Then** le chargement des fichiers YAML est centralisé dans `YamlService` (jamais directement dans le Controller)
6. **Given** les Drivers scaffoldés en Story 1.1 — **Then** `DriverInterface`, `ClaudeDriver`, `GeminiDriver` dans `app/Drivers/` sont **déjà présents** et ne doivent pas être recréés (ne pas toucher ces fichiers)

## Tasks / Subtasks

- [x] **T1 — Installer symfony/yaml et enregistrer les routes API** (AC: 1)
  - [x] `composer require symfony/yaml` depuis `backend/`
  - [x] Dans `backend/bootstrap/app.php`, ajouter la ligne `api: __DIR__.'/../routes/api.php',` dans le bloc `->withRouting(...)` (après `web:`, avant `commands:`)
  - [x] Créer `backend/routes/api.php` avec la route `GET /workflows`

- [x] **T2 — Créer le dossier workflows/ et un exemple YAML** (AC: 1, 3)
  - [x] Créer le dossier `xu-workflow/workflows/` (au même niveau que `backend/` et `frontend/`)
  - [x] Créer `workflows/example.yaml` avec le schéma minimal valide (voir §YAML Schema)

- [x] **T3 — Créer YamlService** (AC: 1, 3, 5)
  - [x] Créer `backend/app/Services/YamlService.php`
  - [x] Méthode `loadAll(): array` — glob `config('xu-workflow.workflows_path').'/*.yaml'`, parse chaque fichier via `Symfony\Component\Yaml\Yaml::parseFile()`, catch `ParseException`, exclure silencieusement les invalides
  - [x] Méthode `validate(array $data): bool` — vérifie la présence de `name` (string non vide) et `agents` (array non vide avec chaque agent ayant `id`, `engine`)

- [x] **T4 — Créer WorkflowResource et WorkflowController** (AC: 1, 2, 4)
  - [x] Créer `backend/app/Http/Resources/WorkflowResource.php` — transformation camelCase des champs (voir §WorkflowResource)
  - [x] Créer `backend/app/Http/Controllers/WorkflowController.php` — méthode `index()` : appel `YamlService::loadAll()` + retour `WorkflowResource::collection()`
  - [x] Le Controller ne contient aucune logique métier — uniquement orchestration

- [x] **T5 — Tests Feature** (AC: 1, 2, 3)
  - [x] Créer `backend/tests/Feature/WorkflowControllerTest.php`
  - [x] Test : GET /api/workflows retourne 200 avec un array non-wrappé
  - [x] Test : un YAML malformé dans le dossier n'empêche pas les autres d'apparaître
  - [x] Test : les champs sont en camelCase dans la réponse

### Review Findings (2026-04-04)

- [x] [Review][Patch] `workflows_path` null → glob `/*.yaml` filesystem root — guard `is_string && !== ''` ajouté avant le glob [backend/app/Services/YamlService.php — méthode `loadAll()`]
- [x] [Review][Patch] Agent `id`/`engine` non validés comme strings non-vides — `is_string() && !== ''` ajouté dans `validate()` [backend/app/Services/YamlService.php — méthode `validate()`]
- [x] [Review][Patch] `timeout` non casté en entier — `(int)` cast ajouté dans WorkflowResource [backend/app/Http/Resources/WorkflowResource.php]
- [x] [Review][Patch] Exceptions non-ParseException non catchées — `catch (\Throwable)` ajouté après le catch ParseException [backend/app/Services/YamlService.php — bloc `catch`]
- [x] [Review][Patch] Test tearDown fragile si exception — try/finally ajouté dans tearDown [backend/tests/Feature/WorkflowControllerTest.php]
- [x] [Review][Defer] Pas d'authentification sur `GET /api/workflows` — intentionnel : localhost single-user, pas d'auth dans ce projet [backend/routes/api.php] — deferred, pre-existing
- [x] [Review][Defer] AC 4 (422/YAML_INVALID) non implémenté — spec dit "si demandé explicitement", aucun endpoint de validation explicite n'est dans le scope de cette story — deferred, pre-existing
- [x] [Review][Defer] Extension `.yml` non scannée — spec utilise `.yaml` de façon consistante, choix délibéré [backend/app/Services/YamlService.php] — deferred, pre-existing
- [x] [Review][Defer] `base_path('../workflows')` fragile dans un container — pré-existant Story 1.1, non introduit par cette story [backend/config/xu-workflow.php] — deferred, pre-existing
- [x] [Review][Defer] `JsonResource::withoutWrapping()` global — intentionnel : toutes les Resources du projet doivent être sans wrapper [backend/app/Providers/AppServiceProvider.php] — deferred, pre-existing

---

## Dev Notes

### §CRITIQUE — État réel du backend (lire avant tout)

**`bootstrap/app.php` n'a PAS de fichier api.php enregistré** — Laravel 13 ne l'inclut pas par défaut. Sans cette ligne, toute requête `/api/*` retournera 404 peu importe les routes définies.

Ajout requis dans `backend/bootstrap/app.php` :

```php
return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',   // ← AJOUTER CETTE LIGNE
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
```

**`routes/api.php` n'existe pas** — créer le fichier (Laravel ne le génère pas automatiquement).

**`app/Http/Resources/`** — le dossier n'existe pas. Le créer avec `WorkflowResource.php`.

**`app/Services/`** — le dossier existe mais est vide. `YamlService` va ici.

**`app/Http/Controllers/`** — contient uniquement `Controller.php` (base). `WorkflowController` étend cette base.

**`DriverInterface` déjà scaffoldée en Story 1.1** — ne pas recréer, ne pas modifier. Les fichiers `app/Drivers/DriverInterface.php`, `ClaudeDriver.php`, `GeminiDriver.php` sont présents et corrects.  
⚠️ **Attention** : l'AC de Story 1.3 dans les epics mentionne encore `kill(int $pid)` — c'est une erreur de synchro. Story 1.1 l'a patchée en `cancel(string $jobId)` (voir Review Findings Story 1.1). Ne pas revenir à `kill()`.

**`JsonResource::withoutWrapping()`** déjà configuré dans `AppServiceProvider::boot()` ✅ — ne pas retoucher.

**`config/xu-workflow.php`** existe avec `workflows_path => base_path('../workflows')` → résout en `/path/to/xu-workflow/workflows/` ✅

---

### §YAML Schema — Structure attendue

Les YAML workflow résident dans `xu-workflow/workflows/` (pas dans `backend/`).

**Champs obligatoires pour la Story 1.3 :**

```yaml
name: "Feature Dev"           # string non-vide — libellé affiché dans le sélecteur
project_path: "/chemin/vers"  # utilisé Epic 2, inclure pour validation future
agents:
  - id: pm                    # kebab-case — identifiant unique
    engine: claude-code       # "claude-code" | "gemini-cli"
    timeout: 120              # secondes
    steps:
      - "Analyser le brief"
    system_prompt: |
      Tu es un PM...
```

**Exemple `workflows/example.yaml` à créer :**

```yaml
name: "Example Workflow"
project_path: "/tmp/example"
agents:
  - id: agent-one
    engine: claude-code
    timeout: 60
    steps:
      - "Étape 1"
    system_prompt: "Tu es un agent de démonstration."
```

**Champs exposés par `GET /api/workflows` :**
- Du workflow : `name`, `file` (nom du fichier YAML, ex: `example.yaml`)
- De chaque agent : `id`, `engine`, `timeout`
- Les autres champs (`system_prompt`, `steps`, `project_path`) ne sont PAS exposés dans cette story

---

### §WorkflowResource — Implémentation exacte

```php
<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class WorkflowResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'name'   => $this->resource['name'],
            'file'   => $this->resource['file'],
            'agents' => array_map(fn($agent) => [
                'id'      => $agent['id'],
                'engine'  => $agent['engine'],
                'timeout' => $agent['timeout'] ?? config('xu-workflow.default_timeout'),
            ], $this->resource['agents'] ?? []),
        ];
    }
}
```

**Règle absolue :** La transformation camelCase se fait ici uniquement. Si les clés PHP sont déjà en camelCase, aucune transformation supplémentaire n'est nécessaire pour cette story. Si un champ YAML futur a un underscore (`system_prompt`), le transformer en camelCase dans la Resource.

---

### §YamlService — Implémentation exacte

```php
<?php

namespace App\Services;

use Symfony\Component\Yaml\Yaml;
use Symfony\Component\Yaml\Exception\ParseException;

class YamlService
{
    public function loadAll(): array
    {
        $path = config('xu-workflow.workflows_path');
        $files = glob($path . '/*.yaml') ?: [];
        $workflows = [];

        foreach ($files as $filePath) {
            try {
                $data = Yaml::parseFile($filePath);
                if ($this->validate($data)) {
                    $data['file'] = basename($filePath);
                    $workflows[] = $data;
                }
            } catch (ParseException) {
                // YAML malformé — exclure silencieusement
            }
        }

        return $workflows;
    }

    public function validate(array $data): bool
    {
        return isset($data['name']) && is_string($data['name']) && $data['name'] !== ''
            && isset($data['agents']) && is_array($data['agents']) && count($data['agents']) > 0
            && isset($data['agents'][0]['id'], $data['agents'][0]['engine']);
    }
}
```

---

### §WorkflowController — Implémentation exacte

```php
<?php

namespace App\Http\Controllers;

use App\Http\Resources\WorkflowResource;
use App\Services\YamlService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class WorkflowController extends Controller
{
    public function __construct(private readonly YamlService $yamlService) {}

    public function index(): AnonymousResourceCollection
    {
        $workflows = $this->yamlService->loadAll();
        return WorkflowResource::collection(collect($workflows));
    }
}
```

**Règle :** Le Controller ne fait qu'orchestrer. Aucune logique YAML ici.

---

### §Routes API — routes/api.php

```php
<?php

use App\Http\Controllers\WorkflowController;
use Illuminate\Support\Facades\Route;

Route::get('/workflows', [WorkflowController::class, 'index']);
```

Laravel 13 avec `->withRouting(api: ...)` préfixe automatiquement les routes du fichier `api.php` par `/api`. La route est donc accessible via `GET /api/workflows`.

---

### §Format de réponse attendu

```json
[
  {
    "name": "Example Workflow",
    "file": "example.yaml",
    "agents": [
      {
        "id": "agent-one",
        "engine": "claude-code",
        "timeout": 60
      }
    ]
  }
]
```

**Points clés :**
- Array à la racine (pas `{ "data": [...] }`) — garanti par `JsonResource::withoutWrapping()` ✅
- Tous les champs en camelCase
- `timeout` utilise `config('xu-workflow.default_timeout')` (120) si absent du YAML

---

### §Format d'erreur uniforme

Si une validation explicite est implémentée (hors scope principal Story 1.3) :

```json
{ "message": "Fichier YAML invalide ou champs obligatoires manquants", "code": "YAML_INVALID" }
```

HTTP 422. Ne jamais exposer la stack trace PHP dans la réponse.

---

### §Tests Feature

```php
// tests/Feature/WorkflowControllerTest.php

public function test_get_workflows_returns_200(): void
{
    // Créer un YAML temporaire dans le dossier workflows
    // GET /api/workflows → 200 + array JSON
    $response = $this->getJson('/api/workflows');
    $response->assertStatus(200);
    $response->assertJsonStructure([['name', 'file', 'agents']]);
}

public function test_malformed_yaml_is_excluded(): void
{
    // YAML malformé présent → les autres workflows restent dans la liste
}

public function test_response_fields_are_camel_case(): void
{
    // Vérifier que les champs retournés sont en camelCase
}
```

**Convention tests Laravel :** `tests/Feature/` pour les tests d'intégration HTTP. Utiliser les helpers `$this->getJson()`, `assertStatus()`, `assertJsonStructure()`.

---

### §Guardrails — Erreurs à ne pas commettre

| ❌ Interdit | ✅ Correct |
|---|---|
| Oublier `api:` dans `bootstrap/app.php` | Ajouter `api: __DIR__.'/../routes/api.php'` dans `->withRouting(...)` |
| Logique YAML dans le Controller | Toute logique dans `YamlService` |
| Transformation camelCase dans les Services | camelCase uniquement dans `WorkflowResource` |
| Recréer `DriverInterface` / `ClaudeDriver` / `GeminiDriver` | Ces fichiers existent et sont corrects — ne pas toucher |
| Utiliser `kill(int $pid)` dans les Drivers | La méthode s'appelle `cancel(string $jobId)` (patch Story 1.1) |
| Retourner `{ "data": [...] }` | `WorkflowResource::collection()` avec `withoutWrapping()` retourne un array directement |
| Appel direct à `file_get_contents()` ou `yaml_parse_file()` | Utiliser `Symfony\Component\Yaml\Yaml::parseFile()` |
| Créer le dossier `workflows/` dans `backend/` | Le dossier est `xu-workflow/workflows/` — au niveau racine du projet |
| Laisser `routes/api.php` inexistant | Créer le fichier, Laravel ne le génère pas automatiquement en v13 |

---

### §Vérification

```bash
cd backend
php artisan serve  # port 8000

# Dans un autre terminal :
curl http://localhost:8000/api/workflows
# Attendu : [{"name": "Example Workflow", "file": "example.yaml", "agents": [...]}]

php artisan test --filter WorkflowControllerTest
# Attendu : tous les tests verts
```

---

### §Structure finale attendue après Story 1.3

```
xu-workflow/
├── workflows/
│   └── example.yaml              ← nouveau
├── backend/
│   ├── bootstrap/app.php         ← modifié (api routes ajoutées)
│   ├── routes/
│   │   ├── web.php               ← inchangé
│   │   └── api.php               ← nouveau
│   ├── app/
│   │   ├── Http/
│   │   │   ├── Controllers/
│   │   │   │   └── WorkflowController.php   ← nouveau
│   │   │   └── Resources/
│   │   │       └── WorkflowResource.php     ← nouveau
│   │   ├── Services/
│   │   │   └── YamlService.php              ← nouveau
│   │   └── Drivers/              ← inchangé (Story 1.1)
│   └── tests/Feature/
│       └── WorkflowControllerTest.php       ← nouveau
```

---

### Apprentissages des stories précédentes applicables

**Story 1.1 :**
- `config/xu-workflow.php` contient `workflows_path => base_path('../workflows')` — chemin correct ✅
- `JsonResource::withoutWrapping()` déjà en place — ne pas re-configurer
- `DriverInterface::cancel(string $jobId)` — PAS `kill(int $pid)` (patch appliqué)
- Laravel 13.3.0 (pas 13.1.1 — version patch ultérieure, compatible)
- `app/Services/` existe en tant que dossier vide — le créer avec `YamlService.php` directement

**Story 1.2 :**
- Story frontend uniquement — pas d'impact sur le backend de Story 1.3

---

### Références

- [Source: docs/planning-artifacts/epics.md#Story-1.3] — AC et user story
- [Source: docs/planning-artifacts/architecture.md#API-Communication-Patterns] — format REST, Resources camelCase, sans wrapper
- [Source: docs/planning-artifacts/architecture.md#Implementation-Patterns] — naming conventions, structure dossiers
- [Source: docs/planning-artifacts/epics.md#Additional-Requirements] — règles API sans wrapper, format d'erreur uniforme
- [Source: docs/implementation-artifacts/1-1-initialisation-des-projets-next-js-et-laravel.md#Review-Findings] — patch `cancel()` vs `kill()`, config workflows_path
- [Source: docs/planning-artifacts/architecture.md#Enforcement-Guidelines] — règles agents IA

## Dev Agent Record

### Agent Model Used

claude-sonnet-4-6

### Debug Log References

- PHPUnit 12 requiert l'attribut `#[Test]` — l'annotation `/** @test */` n'est plus reconnue
- `bootstrap/app.php` sans `api:` donnait 404 sur toutes les routes API — ajout de la ligne corrigé
- `validate()` signature changée en `mixed` au lieu de `array` pour gérer le cas où `Yaml::parseFile()` retourne null sur fichier vide

### Completion Notes List

- `composer require symfony/yaml` installé avec succès (symfony/yaml 7.x)
- `bootstrap/app.php` : `api: __DIR__.'/../routes/api.php'` ajouté dans `->withRouting()`
- `routes/api.php` créé : `GET /api/workflows` → `WorkflowController@index`
- `workflows/example.yaml` créé au niveau racine du projet
- `app/Services/YamlService.php` : `loadAll()` + `validate()` — exclut silencieusement les YAML invalides ou manquant `name`/`agents`/`id`/`engine`
- `app/Http/Resources/WorkflowResource.php` : expose uniquement `name`, `file`, et par agent `id`, `engine`, `timeout` (défaut 120s)
- `app/Http/Controllers/WorkflowController.php` : DI `YamlService`, zéro logique métier
- `tests/Feature/WorkflowControllerTest.php` : 8 tests, 30 assertions — tous verts
- Suite complète : 10/10 tests, 0 régression

### File List

- backend/bootstrap/app.php (modifié — `api:` ajouté dans `->withRouting()`)
- backend/routes/api.php (nouveau)
- backend/app/Services/YamlService.php (nouveau)
- backend/app/Http/Resources/WorkflowResource.php (nouveau)
- backend/app/Http/Controllers/WorkflowController.php (nouveau)
- backend/tests/Feature/WorkflowControllerTest.php (nouveau)
- workflows/example.yaml (nouveau)

### Change Log

- 2026-04-04 : Implémentation Story 1.3 — API Laravel GET /api/workflows avec YamlService, WorkflowResource, WorkflowController
