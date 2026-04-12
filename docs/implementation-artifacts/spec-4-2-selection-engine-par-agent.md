---
title: 'Sélection du driver CLI par agent selon engine YAML'
type: 'feature'
created: '2026-04-12'
status: 'done'
baseline_commit: 'ecf32df93f1ca31581692f997d53d02334bcf606'
context: []
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** `RunService` injecte un `DriverInterface` unique résolu au niveau container — le champ `engine` de chaque agent dans le YAML est complètement ignoré, tous les agents s'exécutent avec `ClaudeDriver` quelle que soit la valeur spécifiée.

**Approach:** Introduire un `DriverResolver` injectable dans `RunService`, qui retourne le driver approprié (`ClaudeDriver` ou `GeminiDriver`) selon `agent['engine']` à chaque itération de la boucle agents. Corriger `GeminiDriver` pour utiliser `--output-format json` (sinon `validateJsonOutput()` échoue systématiquement). Ajouter une validation whitelist dans `YamlService` pour les valeurs d'engine connues.

## Boundaries & Constraints

**Always:**
- L'engine est résolu depuis `agent['engine']` du YAML — jamais un override au niveau run ou request
- `DriverResolver` est injecté dans `RunService` via le constructeur — aucune résolution d'implémentation concrète directement dans les services
- `GeminiDriver` doit passer `--output-format json` pour être compatible avec `validateJsonOutput()`
- `YamlService.validate()` rejette tout engine hors de `['claude-code', 'gemini-cli']`
- Au retry depuis checkpoint, l'engine est re-dérivé du YAML (pas persisté dans checkpoint.json)
- Tous les tests unitaires `RunService*` existants continuent de passer après adaptation des mocks

**Ask First:**
- Si un troisième engine (ex: `openai-codex`) doit être supporté dès cette story

**Never:**
- Pas de sélecteur d'engine au niveau de l'UI ou de la request `/runs` — l'engine vit dans le YAML
- Ne pas corriger `--append-system-prompt` dans GeminiDriver (pré-existant, hors scope)
- Pas de persistance de l'engine dans `checkpoint.json`

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| Agent claude-code | `agent['engine'] = 'claude-code'` | `ClaudeDriver.execute()` appelé | N/A |
| Agent gemini-cli | `agent['engine'] = 'gemini-cli'` | `GeminiDriver.execute()` appelé | N/A |
| Workflow mixte | agent 1 `claude-code`, agent 2 `gemini-cli` | Drivers alternés correctement par boucle | N/A |
| Engine invalide | `agent['engine'] = 'openai-gpt'` | `YamlService.validate()` retourne false → lancement rejeté | 500 / `WORKFLOW_LOAD_ERROR` |
| Engine absent du YAML | `agent` sans clé `engine` | Rejeté par validation pré-existante (ligne 88 YamlService) | idem |
| Retry depuis checkpoint | `executeFromCheckpoint()` — engine re-lu du YAML | Driver correct par agent, même comportement | N/A |

</frozen-after-approval>

## Code Map

- `backend/app/Drivers/GeminiDriver.php` — ajouter `--output-format json` à la commande
- `backend/app/Drivers/DriverResolver.php` — nouvelle classe : `for(string $engine): DriverInterface`
- `backend/app/Services/YamlService.php` — valider `agent['engine']` contre whitelist `['claude-code', 'gemini-cli']`
- `backend/app/Services/RunService.php` — remplacer `DriverInterface $driver` par `DriverResolver $driverResolver` ; résoudre driver par agent dans `executeAgents()`
- `backend/app/Providers/AppServiceProvider.php` — retirer le binding `DriverInterface → ClaudeDriver` (plus utilisé directement)
- `backend/tests/Unit/RunServiceTest.php` — adapter mock `DriverInterface` → mock `DriverResolver`
- `backend/tests/Unit/RunServiceRetryTest.php` — idem
- `backend/tests/Unit/RunServiceTimeoutTest.php` — idem
- `backend/tests/Unit/RunServiceRetryFromCheckpointTest.php` — idem + ajouter cas engine `gemini-cli`

## Tasks & Acceptance

**Execution:**
- [x] `backend/app/Drivers/GeminiDriver.php` -- ajouter `--output-format json` dans la commande `gemini -p` -- sinon `validateJsonOutput()` rejette toute réponse Gemini
- [x] `backend/app/Drivers/DriverResolver.php` -- créer classe avec `for(string $engine): DriverInterface`, lève `\InvalidArgumentException` si engine inconnu -- isole la résolution driver, garde RunService agnostique des concrétions
- [x] `backend/app/Services/YamlService.php` -- dans `validate()`, ajouter vérification `in_array($agent['engine'], ['claude-code', 'gemini-cli'], true)` après le check non-vide existant -- échoue tôt sur un engine non supporté
- [x] `backend/app/Services/RunService.php` -- remplacer constructeur `DriverInterface $driver` par `DriverResolver $driverResolver` ; dans `executeAgents()`, résoudre `$driver = $this->driverResolver->for($agent['engine'])` en tête de boucle -- implémente le routage per-agent
- [x] `backend/app/Providers/AppServiceProvider.php` -- supprimer `$this->app->bind(DriverInterface::class, ClaudeDriver::class)` -- binding rendu obsolète par DriverResolver
- [x] `backend/tests/Unit/RunService*.php` (4 fichiers) -- adapter tous les mocks `DriverInterface` en mock `DriverResolver` avec `for()` stubbé -- rétablit la couverture existante

**Acceptance Criteria:**
- Given un workflow YAML avec `engine: claude-code`, when un run est lancé, then `ClaudeDriver.execute()` est appelé pour cet agent
- Given un workflow YAML avec `engine: gemini-cli`, when un run est lancé, then `GeminiDriver.execute()` est appelé et retourne du JSON valide (`--output-format json` présent)
- Given un workflow mixte (agents claude-code et gemini-cli intercalés), when le run s'exécute, then chaque agent utilise son propre driver
- Given un YAML avec `engine: openai-gpt`, when `YamlService.validate()` est appelé, then validation échoue et le lancement est rejeté
- Given un run en erreur avec un agent `gemini-cli`, when retry depuis checkpoint, then le driver Gemini est utilisé pour la reprise

## Design Notes

`DriverResolver` reçoit `ClaudeDriver` et `GeminiDriver` par constructeur (Laravel les résout automatiquement). Le `match()` sur la string engine évite les cas par défaut silencieux :

```php
public function for(string $engine): DriverInterface
{
    return match($engine) {
        'claude-code' => $this->claude,
        'gemini-cli'  => $this->gemini,
        default       => throw new \InvalidArgumentException("Unsupported engine: {$engine}"),
    };
}
```

`RunService.executeAgents()` — une ligne ajoutée en tête de boucle, avant `$timeout` :

```php
$driver = $this->driverResolver->for($agent['engine']);
```

## Verification

**Commands:**
- `cd backend && php artisan test --filter RunService` -- expected: tous les tests RunService passent (après adaptation mocks)
- `cd backend && php artisan test` -- expected: suite complète verte

## Suggested Review Order

**Résolution per-agent (entrée principale)**

- Nouvelle factory : `match($engine)` retourne ClaudeDriver ou GeminiDriver — source de vérité unique
  [`DriverResolver.php:1`](../../backend/app/Drivers/DriverResolver.php#L1)

- Constructeur : swap `DriverInterface $driver` → `DriverResolver $driverResolver`
  [`RunService.php:19`](../../backend/app/Services/RunService.php#L19)

- Résolution par agent dans la boucle — une ligne, positionnée avant `$timeout`
  [`RunService.php:124`](../../backend/app/Services/RunService.php#L124)

**Validation engine**

- Whitelist `['claude-code', 'gemini-cli']` ajoutée après le check non-vide existant
  [`YamlService.php:91`](../../backend/app/Services/YamlService.php#L91)

- Flag `--output-format json` ajouté — rend GeminiDriver compatible avec `validateJsonOutput()`
  [`GeminiDriver.php:12`](../../backend/app/Drivers/GeminiDriver.php#L12)

**DI container**

- Binding `DriverInterface → ClaudeDriver` retiré — autowiring Laravel prend le relais
  [`AppServiceProvider.php:16`](../../backend/app/Providers/AppServiceProvider.php#L16)

**Tests**

- Nouveau test AC5 : `expects()->with('gemini-cli')` vérifie le routage sur retry
  [`RunServiceRetryFromCheckpointTest.php:252`](../../backend/tests/Unit/RunServiceRetryFromCheckpointTest.php#L252)

- Pattern de mock adapté (4 fichiers identiques) : resolver stub `for()` → driver mock
  [`RunServiceTest.php:35`](../../backend/tests/Unit/RunServiceTest.php#L35)
