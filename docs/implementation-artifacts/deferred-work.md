# Deferred Work

## Deferred from: code review of 1-1-initialisation-des-projets-next-js-et-laravel (2026-04-04)

- **CORS non configuré** — Laravel `config/cors.php` non modifié. Pas nécessaire en dev local (proxy Next.js gère la séparation), mais à configurer pour tout déploiement non-localhost.
- **php artisan serve single-thread** — Le serveur de développement Laravel ne gère pas les requêtes concurrentes. Non-bloquant pour le dev local, mais à considérer si tests concurrents ou Octane pour prod.
- **Proxy Next.js sans timeout configurable** — Les rewrites Next.js n'exposent pas de timeout configurable. Tout run bloqué côté backend bloquera le fetch frontend jusqu'au timeout browser. À adresser via des timeouts serveur-side dans Laravel pour Epic 2.
- **default_timeout non enforcé dans DriverInterface** — La config `xu-workflow.default_timeout` existe mais l'interface `DriverInterface::execute()` n'a pas de paramètre timeout explicite. Chaque driver devra lire la config manuellement. À formaliser lors de l'implémentation Story 2.1.
- **Tokens d'état agent manquants (cancelled, queued, retrying)** — Seuls idle/working/done/error définis. Les états futurs nécessiteront un ajout dans `globals.css @theme inline`. À faire lors des stories qui introduisent ces états.

## Deferred from: code review of 1-2-design-system-tokens-couleur-dark-mode-et-layout-principal (2026-04-04)

- **`h-full` vs `flex-1` sur le wrapper page.tsx** — `flex-1` est plus correct sémantiquement pour un enfant flex de body. `h-full` fonctionne en pratique. Refactorer lors du prochain rework de page.tsx.
- **`--border`/`--input` opaque vs transparent** — Passés de `oklch(1 0 0 / 10%)` à `oklch(0.32 0 0)`. Peut légèrement affecter les composants shadcn utilisant des opacités composited (`/30` etc.). Surveiller lors de Story 1.3+ quand les formulaires/inputs arrivent.
- **Tokens agent sans variantes dark mode** — `--color-agent-*` sont globaux (pas de dark override). Intentionnel (couleurs d'état sémantiques). Vérifier le contraste sur fond zinc-800 lors de Story 1.5 (AgentCard).
- **Sidebar masquée < 1024px sans affordance d'accès** — `hidden lg:flex` masque complètement la sidebar en mobile/tablette. À adresser en Story 2.7b avec un bouton toggle ou drawer.

## Deferred from: code review of 1-3-api-laravel-chargement-validation-et-exposition-des-workflows-yaml (2026-04-04)

- **Pas d'authentification sur GET /api/workflows** — Intentionnel, usage localhost single-user. Si le projet évolue vers un déploiement réseau, ajouter middleware auth sur les routes API.
- **AC 4 — 422/YAML_INVALID non implémenté** — La spec dit "si demandé explicitement" (optionnel). Aucun endpoint de validation dédié dans cette story. À implémenter si un besoin de validation explicite émerge (ex: endpoint `POST /api/workflows/validate`).
- **Extension `.yml` non scannée** — `glob('*.yaml')` ignore les fichiers `.yml`. Choix délibéré (spec utilise `.yaml` partout). À étendre si des utilisateurs apportent des fichiers `.yml`.
- **`base_path('../workflows')` fragile hors structure repo** — Résout correctement dans la structure actuelle. En cas de containerisation ou déploiement différent, ce chemin relatif devrait être remplacé par une variable d'environnement absolue.
- **`JsonResource::withoutWrapping()` global dans AppServiceProvider** — Intentionnel : toutes les Resources du projet ne doivent pas avoir le wrapper `data`. Si une future Resource a besoin du wrapper, utiliser `protected static $wrap = 'data'` localement.

## Deferred from: code review of 1-4-selecteur-de-workflow-et-rechargement-dynamique (2026-04-04)

- **Pas de validation runtime de `res.json()`** — Le cast TypeScript `Workflow[]` est nominal uniquement. Pour un backend localhost contrôlé, acceptable. Si le projet expose une API publique, ajouter un parser/validator (ex: Zod).
- **Clés `file` dupliquées dans la réponse API** — Le `YamlService` Laravel exclut les YAML invalides et scanne par glob, rendant les doublons très improbables. À surveiller si le dossier `workflows/` est partagé entre plusieurs environnements.
- **`useCallback` deps sur les setters Zustand** — Les setters créés par Zustand sont stables (même référence entre renders). Les inclure dans `useCallback` deps est inoffensif mais crée du bruit de lint potentiel.

## Deferred from: code review of 1-5-diagramme-statique-des-agents-d-un-workflow-selectionne (2026-04-04)

- **AgentDiagram positions Y ignorent le nombre de steps** — `position: { x: 0, y: index * 220 }` est une heuristique fixe. `fitView` compense l'imprécision pour Story 1.5 (pipeline statique). À réévaluer en Story 2.6 si les cards ont des hauteurs très variables selon les steps.
- **AgentDiagram containerHeight statique** — `agents.length * 220 + 80` ignore la hauteur réelle des cards (variable selon le nombre de steps par agent). Scrollbar possible sur de grandes cartes. À raffiner en Story 2.6 avec un layout observer ou calcul par steps.
- **AgentDiagram absence de filtre frontend agent.id vide** — Le backend filtre déjà les agents avec id vide (test `agent_with_empty_id_is_excluded` confirmé). Un guard frontend `filter(agent => agent.id?.trim())` serait de la defense-in-depth. À ajouter si d'autres sources d'agents apparaissent (ex: YAML generé dynamiquement).

## Deferred from: code review of 2-1-moteur-d-execution-laravel-spawn-cli-sequentiel-et-contrat-json (2026-04-05)

- **`cancel()` supprimé de `DriverInterface`** — Était un stub `throw RuntimeException('Not implemented')`, aucun appelant connu. À redéfinir proprement en Story 2.2 (timeout/kill mechanism).
- **Pas d'auth/rate-limit sur `POST /runs`** — Route ouverte intentionnellement pour MVP localhost. À sécuriser avant tout déploiement réseau (middleware auth + throttle).
- **`GeminiDriver --yolo` sans restriction d'outils** — Flag requis par la CLI Gemini pour l'auto-approve. Pas d'équivalent `--allowedTools`. Documenter les implications dans la spec de déploiement.
- **`project_path` non validé comme répertoire existant** — `validate()` vérifie uniquement que c'est une string non vide. `Process::path('/nonexistent')` lèvera une `RuntimeException` explicite. Acceptable MVP.
- **IDs agents dupliqués non détectés dans `validate()`** — Deux agents avec le même `id` produisent des résultats ambigus dans `agentResults[]`. À ajouter si la validation devient plus stricte.
- **`RunResource extends JsonResource` pour un array plain** — Fonctionne correctement, mais `JsonResource` est conçu pour Eloquent. Refactorer si une meilleure abstraction émerge.
- **`runId` non persisté** — UUID généré et retourné mais non sauvegardé. Toute requête ultérieure par runId échouera. Adressé par Story 2.3 (filesystem persistence).
- **Pas de limite de taille sur stdout capturé** — `$result->output()` bufferise tout en mémoire. Agent retournant des MB de données peut saturer le worker. À adresser par streaming ou limite configurable.
- **`--allowedTools` hardcodé dans ClaudeDriver** — Liste `"Bash,Read,Write,Edit"` fixe pour MVP. À rendre configurable par agent (via YAML) si les workflows nécessitent des outils différents.
- **`startedAt` inclut le temps de chargement YAML** — `microtime(true)` appelé avant `yamlService->load()`. `duration` inclut le parsing YAML (~ms). Cosmétique mais trompeur pour l'analyse perf.

## Deferred from: code review of 2-2-timeout-par-tache-et-annulation-de-run (2026-04-06)

- **TOCTOU dans `destroy()`** — `cache()->has()` puis `cache()->put()` ne sont pas atomiques. Un run terminant entre les deux appels pose un flag orphelin. Acceptable MVP (finally block nettoie dans tous les cas). À adresser via opération atomique (Lua script Redis ou lock) si la concurrence devient réelle.
- **Pas d'auth/rate-limit sur `DELETE /runs/{id}`** — Route ouverte intentionnellement pour MVP localhost. À sécuriser avant tout déploiement réseau (middleware auth + throttle), cohérent avec la décision prise pour `POST /runs`.
- **Run ID exposé dans le message 404** — `"Run not found or already completed: {$id}"` répercute l'ID fourni par le client. Pas d'injection possible (UUID validé par le routeur), mais peut faciliter l'énumération en environnement multi-user. À filtrer si authentification ajoutée.
- **`(int) config(...)` fragile si valeur null** — `(int) null === 0`, ce qui passerait silencieusement un timeout de 0 à `Process::timeout(0)` (désactivation totale). À remplacer par `(int) config(...) ?: 120` ou validation dans `config/xu-workflow.php`.
- **`timeout: "60"` (string YAML) non géré** — `isset($agent['timeout']) && is_int($agent['timeout'])` rejette les strings numériques. Le Symfony Yaml parser peut retourner des ints pour les valeurs sans guillemets, mais les guillemets (`timeout: "60"`) produiront une string. À documenter dans la spec YAML ou ajouter `is_numeric()` + cast.
