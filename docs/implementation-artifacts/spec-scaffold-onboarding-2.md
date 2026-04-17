---
title: 'Scaffold Onboarding — Générateur de Workflow IA'
type: 'feature'
created: '2026-04-17'
status: 'done'
baseline_commit: '66c5f8ee1c9efa5e2a691e62b0edb2a2b9ca16b4'
context: []
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problème :** Écrire un YAML de workflow à la main est fastidieux et source d'erreurs de syntaxe ou de logique d'enchaînement — aucun assistant ne guide l'utilisateur.

**Approche :** Ajouter un bouton "Nouveau Workflow" dans la `Sidebar`. Un dialog `WorkflowWizard` s'ouvre : l'utilisateur décrit son objectif en langage naturel, le backend appelle `GeminiDriver` pour générer le YAML, le frontend affiche le résultat éditable avec la liste des agents proposés, puis l'utilisateur nomme et sauvegarde le fichier.

## Boundaries & Constraints

**Always:**
- Le YAML généré doit respecter le schéma strict validé par `YamlService::validate()` (champs requis : `name`, `project_path`, `agents[]` avec `id`, `engine`, `steps`).
- Proposer des `id` d'agents et des `steps` pertinents par rapport au brief utilisateur.
- Valider le YAML via `YamlService::validate()` côté backend avant de renvoyer la réponse au frontend.
- Permettre l'édition manuelle du YAML généré dans le frontend avant sauvegarde.

**Ask First:**
- Si `workflows/{filename}.yaml` existe déjà, demander confirmation à l'utilisateur avant d'écraser.

**Never:**
- Ne pas écraser un fichier existant sans confirmation explicite.
- Ne pas streamer la génération vers le frontend (réponse complète uniquement pour cette feature).

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| Génération réussie | `brief` valide, Gemini retourne un YAML valide | HTTP 200 `{ yaml: "...", parsed: {...} }` | N/A |
| YAML malformé | Gemini génère un YAML invalide ou incomplet | HTTP 422 `{ error: "...", raw_yaml: "..." }` | Frontend avance en step 2, affiche le YAML brut dans la textarea éditable + message d'erreur |
| Sauvegarde nouveau fichier | `filename` + `yaml_content` valides, fichier inexistant | Fichier créé, workflow ajouté au store Zustand, dialog fermé | N/A |
| Sauvegarde — conflit | `filename` déjà présent dans `workflows/` | HTTP 409 `{ error: "file_exists" }` | Frontend affiche confirmation `[Écraser] [Annuler]` |
| Brief trop court | `brief` vide ou < 10 caractères | Validation frontend bloque l'envoi | Message d'erreur inline |

</frozen-after-approval>

## Code Map

- `backend/app/Drivers/GeminiDriver.php` -- ajouter `prompt(string $systemPrompt, string $userPrompt, int $timeout = 60): string` (appel bloquant, collecte output complet)
- `backend/app/Services/YamlService.php` -- ajouter `save(string $filename, string $yamlContent, bool $force = false): void`
- `backend/app/Http/Controllers/WorkflowController.php` -- ajouter `generate()` et `store()`
- `backend/routes/api.php` -- enregistrer `POST /api/workflows/generate` et `POST /api/workflows`
- `frontend/src/components/WorkflowWizard.tsx` -- nouveau Dialog : brief → YAML éditable → filename → sauvegarde
- `frontend/src/components/Sidebar.tsx` -- ajouter bouton "Nouveau Workflow" qui ouvre `WorkflowWizard`
- `frontend/src/stores/workflowStore.ts` -- ajouter action `addWorkflow(workflow)` pour mise à jour immédiate après sauvegarde

## Tasks & Acceptance

**Execution:**
- [x] `backend/app/Drivers/GeminiDriver.php` -- Ajouter `prompt(string $systemPrompt, string $userPrompt, int $timeout = 60): string` : même logique buffer/parseLine que `execute()` mais sans `--yolo`, path=`sys_get_temp_dir()`, retourner `$accumulatedResponse ?: $result->output()` (fallback sur output brut si aucun message JSON) -- Le driver actuel n'expose qu'un `execute()` streaming ; il faut un appel one-shot pour la génération de YAML
- [x] `backend/app/Services/YamlService.php` -- Ajouter `save(string $filename, string $yamlContent, bool $force = false): void` : basename sanitization, extension `.yaml` auto-ajoutée, lève `\RuntimeException("already exists")` si conflit et `$force=false`, vérifier le retour de `file_put_contents()` et lever `\RuntimeException("write failed")` si `false` -- Aucun mécanisme de persistance n'existe actuellement ; validate() DOIT rester relaxée (project_path vide OK) pour la compatibilité avec le preview generate()
- [x] `backend/app/Http/Controllers/WorkflowController.php` -- Ajouter `generate(Request $request)` : valide `brief` (requis, min:10), construit le system prompt Gemini avec le schéma YAML embarqué, appelle `GeminiDriver::prompt()`, strip les markdown fences, valide via `YamlService::validate()`, retourne `{ yaml, parsed }` ou 422 avec `raw_yaml` -- Endpoint de génération IA
- [x] `backend/app/Http/Controllers/WorkflowController.php` -- Ajouter `store(Request $request)` : (1) valide `filename` (regex `^[a-z0-9-]+$`), `yaml_content`, `force` booléen optionnel ; (2) parser `yaml_content` via `Yaml::parse()` — 422 si ParseException ; (3) appeler `YamlService::validate($parsed)` ET vérifier que `$parsed['project_path'] !== ''` — 422 si invalide ou project_path vide ; (4) appeler `YamlService::save()` — 409 si conflit ; (5) appeler `YamlService::load()` dans un try/catch — 500 si exception ; (6) retourner `WorkflowResource` en 201 -- Endpoint de persistance ; valider le YAML edité avant écriture pour éviter de sauvegarder un workflow inexécutable
- [x] `backend/routes/api.php` -- Enregistrer `POST /api/workflows/generate` → `WorkflowController@generate` et `POST /api/workflows` → `WorkflowController@store` -- Expose les deux nouveaux endpoints
- [x] `frontend/src/components/WorkflowWizard.tsx` -- Créer le composant Dialog 2-step : step 1 = brief textarea + bouton "Générer" ; step 2 = YAML textarea éditable + agent badges + filename input + bouton "Créer" + confirmation conflit `[Écraser][Annuler]`. Sur 422 de `/generate` : avancer en step 2 et peupler la textarea YAML avec `raw_yaml` (pour permettre l'édition et la correction). Sur 201 : appeler `addWorkflow(data.data)` et fermer. Utiliser `AbortController` pour annuler les fetch en vol si le dialog se ferme. Ajouter `aria-label="Nouveau Workflow"` sur le bouton `+` dans Sidebar -- Interface principale du wizard
- [x] `frontend/src/components/Sidebar.tsx` -- Ajouter un bouton `+` avec `aria-label="Nouveau Workflow"` au-dessus du sélecteur de workflow, qui ouvre `WorkflowWizard` -- Seul point d'entrée visible
- [x] `frontend/src/stores/workflowStore.ts` -- Ajouter action `addWorkflow(workflow: Workflow)` qui pousse dans `workflows[]` et sélectionne le nouveau workflow -- Évite un rechargement complet après sauvegarde

**Acceptance Criteria:**
- Given la Sidebar est affichée, when l'utilisateur clique sur le bouton `+`, then le dialog `WorkflowWizard` s'ouvre
- Given un brief ≥ 10 caractères soumis, when le backend répond 200, then le YAML généré s'affiche dans une textarea éditable (step 2) et la liste des agents proposés est visible
- Given generate() retourne 422 avec `raw_yaml`, when la réponse arrive, then le wizard avance en step 2 avec `raw_yaml` dans la textarea éditable et le message d'erreur visible
- Given un YAML affiché en step 2, when l'utilisateur modifie la textarea, then le contenu envoyé à `POST /api/workflows` reflète la version éditée (pas l'original)
- Given un `filename` unique et un `yaml_content` avec `project_path` non vide, when l'utilisateur clique "Créer", then le fichier est créé, le workflow apparaît dans le sélecteur Sidebar sans rechargement complet, et le dialog se ferme
- Given un `filename` déjà existant, when l'utilisateur clique "Créer", then une confirmation `[Écraser][Annuler]` s'affiche avant toute écriture

## Spec Change Log

### Itération 1 — 2026-04-17

**Finding déclencheur :**
- (bad_spec A) `validate()` relaxée pour les `project_path` vides → un workflow sauvegardé avec `project_path: ""` causerait `Process::path("")` dans le moteur d'exécution (RunService → ClaudeDriver/GeminiDriver), ce qui utilise le répertoire CWD de PHP comme working directory.
- (bad_spec B) `store()` ne validait pas le `yaml_content` édité avant d'écrire — un utilisateur pouvait sauvegarder un YAML structurellement invalide.
- (bad_spec C) Sur 422 avec `raw_yaml`, le wizard restait en step 1 (brief textarea), le `raw_yaml` était stocké en état mais jamais affiché dans la textarea éditable.
- (bad_spec G) AC5 disait `[Renommer]` mais I/O Matrix disait `[Annuler]` — contradiction ; résolu en faveur de `[Annuler]` (l'utilisateur peut modifier le filename dans le champ existant).

**Ce qui a été amendé :**
- Task `GeminiDriver.php` : ajout du fallback `?: $result->output()` sur le return de `prompt()`
- Task `YamlService.php` : ajout de la vérification du retour `file_put_contents()` ; KEEP: validate() reste relaxée (project_path vide autorisé pour preview)
- Task `WorkflowController::store()` : ajout de la validation en 3 étapes : parse YAML → validate() → project_path non vide avant save()
- Task `WorkflowWizard.tsx` : ajout de l'avancement en step 2 sur 422 avec raw_yaml ; ajout AbortController ; ajout aria-label sur bouton +
- AC5 : `[Renommer]` → `[Annuler]`
- AC ajouté : comportement sur 422 avec raw_yaml
- I/O Matrix (frozen) : précision "avance en step 2" sur la ligne YAML malformé

**État connu à éviter :** validate() ne doit PAS re-imposer project_path non-vide — sinon generate() échoue en preview sur les YAMLs générés avec project_path: "". C'est store() qui enforces project_path non-vide séparément.

**KEEP :** Toute la logique de GeminiDriver::prompt() (buffer/parseLine), YamlService::save() (basename, extension auto), WorkflowController::generate() en entier, Routes, workflowStore::addWorkflow(), structure 2-step du wizard, boutons [Écraser][Annuler].

## Design Notes

**Prompt Gemini scaffolder** — Le system prompt doit embarquer le schéma YAML complet avec un exemple minimal (≤15 lignes) et demander à Gemini de répondre avec *uniquement* le bloc YAML, sans prose.

**Extraction du YAML** — Gemini entoure souvent sa réponse de ` ```yaml ... ``` `. Le controller doit stripper les backtick fences avant de passer à `YamlService::validate()`. Regex : `/^```(?:yaml)?\s*\n?(.*?)\n?```\s*$/s`.

**`project_path` dans le wizard** — Laisser vide est valide pour la génération (Gemini génère un preview) ; le backend rejette la sauvegarde si `project_path` est vide dans le YAML final. L'utilisateur doit compléter ce champ dans la textarea YAML avant de cliquer "Créer".

**validate() vs store() — séparation des responsabilités** — `validate()` accepte les `project_path` vides (pour la cohérence avec le flow de preview). `store()` appelle `validate()` PUIS vérifie explicitement `project_path !== ''` pour éviter de persister un workflow inexécutable.

## Verification

**Commands:**
- `cd backend && php artisan test --filter WorkflowControllerTest` -- expected : tous les tests passent, aucune régression sur `index()`
- `cd frontend && npx tsc --noEmit` -- expected : 0 erreur TypeScript

**Manual checks:**
- Ouvrir le wizard, saisir un brief, vérifier que le YAML généré contient des agents avec `id`, `engine`, et `steps` pertinents
- Tenter de sauvegarder avec `project_path: ""` dans la textarea → vérifier que le backend rejette avec 422
- Sauvegarder un workflow valide, vérifier qu'il apparaît dans le sélecteur Sidebar sans rechargement
- Tenter de sauvegarder avec un nom déjà existant → vérifier que `[Écraser][Annuler]` s'affiche

## Suggested Review Order

**Entrée principale — intent du changement**

- Nouveau controller action : point d'entrée de la génération IA + pipeline de validation
  [`WorkflowController.php:30`](../../backend/app/Http/Controllers/WorkflowController.php#L30)

**Génération Gemini (backend)**

- `prompt()` bloquant : buffer JSON, fallback sur stdout brut, sans `--yolo`
  [`GeminiDriver.php:95`](../../backend/app/Drivers/GeminiDriver.php#L95)

- System prompt embarqué + strip markdown fences avant validate()
  [`WorkflowController.php:36`](../../backend/app/Http/Controllers/WorkflowController.php#L36)

**Sauvegarde & Validation (backend)**

- `store()` — pipeline 5 étapes : parse → validate → project_path non vide → save → load
  [`WorkflowController.php:96`](../../backend/app/Http/Controllers/WorkflowController.php#L96)

- Contrainte clé : project_path whitespace-trimmed avant écriture (exécution moteur)
  [`WorkflowController.php:118`](../../backend/app/Http/Controllers/WorkflowController.php#L118)

- `save()` : sanitisation + vérification retour `file_put_contents`
  [`YamlService.php:65`](../../backend/app/Services/YamlService.php#L65)

- `validate()` relaxée : project_path vide autorisé (preview Gemini uniquement)
  [`YamlService.php:89`](../../backend/app/Services/YamlService.php#L89)

**Routage**

- 2 nouveaux endpoints POST : `/workflows/generate` et `/workflows`
  [`api.php:9`](../../backend/routes/api.php#L9)

**Wizard frontend**

- `handleGenerate` : fetch + sur 422 avance en step 2 avec raw_yaml dans textarea
  [`WorkflowWizard.tsx:82`](../../frontend/src/components/WorkflowWizard.tsx#L82)

- `handleStore` : AbortController + gestion 409/422/201
  [`WorkflowWizard.tsx:121`](../../frontend/src/components/WorkflowWizard.tsx#L121)

- Dialog JSX 2 étapes : brief → YAML éditable + badges + filename
  [`WorkflowWizard.tsx:173`](../../frontend/src/components/WorkflowWizard.tsx#L173)

- Bouton `+` avec `aria-label` + rendu du wizard dans la Sidebar
  [`Sidebar.tsx:57`](../../frontend/src/components/Sidebar.tsx#L57)

**Store**

- `addWorkflow` : append + sélection automatique du nouveau workflow
  [`workflowStore.ts:29`](../../frontend/src/stores/workflowStore.ts#L29)
