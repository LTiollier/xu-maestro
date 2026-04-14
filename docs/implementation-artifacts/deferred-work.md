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

## Deferred from: code review of 2-3-contexte-partage-inter-agents-et-artefacts-de-run (2026-04-06)

- **`$agentId` utilisé directement comme nom de fichier** — `'/agents/' . $agentId . '.md'` sans validation. Traversée de chemin possible si un ID contient `../`. Acceptable pour MVP localhost (YAML auteur = utilisateur). Ajouter `basename()` ou validation regex si le YAML devient untrusted. [ArtifactService.php]
- **`session.md` croît sans limite** — Le contenu intégral est passé comme `$context` au driver à chaque agent. Sur des workflows longs, peut dépasser les limites de contexte CLI sans message d'erreur explicite. Envisager une fenêtre glissante ou un résumé en Epic 3+. [ArtifactService::getContextContent]
- **`sanitizeEnvCredentials` faux positifs sur valeurs longues courantes** — Une variable `DB_PASSWORD=localhost` (9 chars, pattern match) redacterait toutes les occurrences de "localhost" dans l'output. Limitation connue de l'heuristique longueur+pattern. Acceptable MVP. [ArtifactService.php:84]
- **`sanitizeEnvCredentials` peut manquer credentials Unicode-escapés** — `str_replace` sur la string brute ne matche pas les séquences `\uXXXX` produites par `json_encode`. Cas très marginal (credential avec caractères Unicode non-ASCII). [ArtifactService.php:84]
- **`$brief` avec newlines peut injecter de faux headers Markdown** — Newlines dans le brief produisent des sections `## Agent:` fictives dans `session.md`. Pas de risque sécurité (localhost, session.md non servi). Sanitiser si session.md devient lisible via API. [ArtifactService.php:22]
- **Chemin `/session.md` dupliqué** — String hardcodée dans `ArtifactService` (put/append/get) et `RunService` (checkpoint context field). Un renommage casse l'un sans casser l'autre. Extraire en constante si besoin. [ArtifactService.php, RunService.php]
- **Ordre de fusion `$_ENV` + `getenv()`** — `array_merge($_ENV, getenv() ?: [])` : `getenv()` écrase `$_ENV` pour la même clé. Dans certains SAPIs les deux divergent. Peu probable de causer un credential manqué en pratique. [ArtifactService.php:86]

## Deferred from: code review of 2-4-stream-sse-evenements-temps-reel-laravel-next-js (2026-04-07)

- **Pas d'authentification sur les routes SSE/DELETE** — Localhost single-user, auth hors scope MVP.
- **`step: 0` hardcodé dans tous les events SSE** — Granularité step-level prévue en Epic 3 (CheckpointService dédié).
- **`runFolder` et `checkpointPath` exposent des chemins serveur absolus dans les payloads SSE** — Localhost single-user, sécurité hors scope MVP.
- **Collision `ArtifactService::initializeRun()` si deux runs dans la même seconde** — Pré-existant story 2.3, hors scope 2.4.
- **`RunCancelledException` émis comme `RunError`** — Impossible de distinguer cancel intentionnel d'une erreur réelle. Pas de `RunCancelled` event par design MVP, à revisiter si l'UX le requiert.
- **Message `CliExecutionException` contient `'claude'` au lieu de l'agentId réel** — Pré-existant dans `ClaudeDriver.php`, hors scope 2.4.
- **`brief` sans validation `max:`** — Hardening sécurité hors scope MVP.
- **`resolveSystemPrompt()` retourne `''` silencieusement si fichier absent** — Pré-existant story 2.1, comportement documenté acceptable MVP.
- **`SseEmitter` listeners enregistrés globalement — `ob_flush()` appelé hors contexte HTTP** — Pas de callers non-HTTP de RunService actuellement, risque latent si CLI Artisan ajouté.
- **`sendKeepAlive()` jamais appelé** — Localhost sans proxy Nginx, timeout non bloquant pour MVP. À activer quand un proxy est introduit (décision D2 code review 2.4).

## Deferred from: code review of 2-5-client-sse-et-mise-a-jour-des-stores-zustand (2026-04-08)

- **`bubbleMessage` non vidé lors d'une transition de status** — Story 2.6/2.7b décidera du comportement visuel des bulles. [agentStatusStore.ts]
- **`AgentBubble.step` non stocké dans le store** — `step: 0` hardcodé par design (Epic 3 granularité step-level). [agentStatusStore.ts]
- **`checkpointPath` sur `RunErrorEvent` non consommé** — Epic 3 (retry depuis checkpoint). [useSSEListener.ts]
- **`RunCompletedEvent.agentCount`/`.status` non stockés dans le store** — Utilisés en Story 2.7b (`RunSummaryModal`). [runStore.ts]
- **`setRunId(null)` ne reset pas `errorMessage`/`duration`** — Par design : `resetRun()` est le chemin de reset complet. [runStore.ts]
- **Race `runStore: 'running'` vs SSE failure** — Story 2.7a (`LaunchBar`) gère la corrélation `runId` + ouverture SSE. [useSSEListener.ts]
- **`runFolder` non validé pour chaîne vide** — Localhost MVP, serveur toujours correct. [sseEventParser.ts]
- **Timeout connexion `EventSource` absent** — Localhost MVP, pas de proxy réseau. [useSSEListener.ts]
- **Race ordering `run.completed` avant dernier `agent.status.changed`** — Laravel garantit l'ordre d'émission. [useSSEListener.ts]
- **Multiples `RUN_ERROR`, dernier gagne** — Serveur émet au plus un `run.error` par run. [runStore.ts]

## Deferred from: code review of 2-6-diagramme-anime-etats-temps-reel-et-transitions-de-handoff (2026-04-08)

- **`agents` subscription dans `AgentDiagramInner` déclenche `useMemo` sur chaque `setAgentBubble`** — `bubbleMessage` n'est pas lu dans `AgentDiagram`, mais tout changement du record `agents` recompute nodes/edges. Impact minimal (≤5 agents, localhost), mais à optimiser avec un sélecteur status-only + `shallow` si la fréquence des bulles devient problématique. [AgentDiagram.tsx]
- **Edge affiche `inactive` quand l'agent source est en `error`** — La logique `agents[agent.id]?.status === 'done' ? 'done' : 'inactive'` collapse `error` → `inactive`. Un état visuel `error` sur l'edge connecteur n'est pas spécifié en 2.6 — à décider si une couleur rouge sur l'edge est souhaitée lors de 3.3 (alerte localisée). [AgentDiagram.tsx]
- **Garantie AC4 "même tick" non applicable avec deux events SSE distincts** — Quand agent N → `done` et agent N+1 → `working` arrivent en deux events SSE séparés, deux renders React se produisent. En pratique invisible sur localhost (< 1ms entre les deux events), mais techniquement non atomique. Fix possible via batching côté frontend (`unstable_batchedUpdates`) ou regroupement des transitions côté backend. [AgentDiagram.tsx, useSSEListener.ts]
- **`slice(0, -1)` sur workflow mono-agent** — Pré-existant depuis 1.5, listé pour complétude. Un workflow avec un seul agent produit zéro edge, comportement correct mais non testé explicitement. [AgentDiagram.tsx]

## Deferred from: code review of 2-7a-launchbar-lancement-et-annulation-d-un-run (2026-04-08)

- **Race SSE/annulation — `run.completed` vs `resetRun()`** — Si un événement SSE `run.completed` arrive juste avant le clic "Annuler", `setRunCompleted` puis `resetRun()` s'exécutent en séquence, écrasant l'état `completed`. Contrainte architecturale concurrente (non fixable sans state machine formelle). [LaunchBar.tsx]
- **`WorkflowSelector` non désactivé pendant un run actif** — L'utilisateur peut changer de workflow alors qu'un run est en cours, laissant un état incohérent (run A tourne, workflow B affiché). Nécessite soit de désactiver le `Select` en état `running`, soit de gérer la transition explicitement. [WorkflowSelector.tsx]
- **États agents non réinitialisés après `run.completed`/`run.error` via SSE** — `agentStatusStore.resetAgents()` n'est appelé qu'au prochain lancement. Les statuts visuels du run précédent persistent dans le diagramme jusqu'à un nouveau clic "Lancer". À gérer dans 2.7b (post-run cleanup). [LaunchBar.tsx, agentStatusStore.ts]

## Deferred from: code review of 3-2-retry-automatique-des-etapes-mandatory (2026-04-10)

- **Pas de cap sur `max_retries`** — `max_retries: 9999999` accepté silencieusement par `is_int()`. Ajouter un cap raisonnable (ex: <= 10) si des workflows externs sont possibles. [RunService.php:58]
- **`mandatory: "true"` (string YAML) silencieusement ignoré** — `=== true` strict : `mandatory: "true"` ou `mandatory: 1` ne déclenche pas de retry. Comportement standard YAML (unquoted = bool natif), documenté dans example.yaml. [RunService.php:57]
- **Annulation non détectée en cours d'exécution driver** — Le check `run:{id}:cancelled` est coopératif (vérifié en tête de boucle), pas au milieu d'un long appel driver. Pre-existing avant retry. [RunService.php:79]
- **Checkpoint pré-agent non mis à jour entre les retries** — Le checkpoint reste figé à l'état pré-agent pendant tous les retries. Intentionnel par spec 3.2 ; Story 3.4 devra décider si les retries doivent écrire un checkpoint intermédiaire avec `attempt`. [RunService.php:62]
- **`error_emitted` tripliqué dans 3 catch** — La logique `cache()->put(error_emitted)` est dupliquée dans les 3 catch finaux. Risque de divergence si un 4ème type d'exception est ajouté sans ajouter la ligne. À centraliser (ex: méthode `emitFinalError()`) lors d'un refactor global de RunService. [RunService.php:111,126,141]
- **Output des tentatives échouées perdu** — L'output (stderr, JSON invalide) de chaque tentative échouée est discardé. Seul l'output du succès final est appendé à session.md. Utile pour le debug de flapping agents ; à adresser si un système de log de run est introduit.
- **`error_emitted` TTL hardcodé 60s** — Pas de relation avec les timeouts configurés du run. Pre-existing. [RunService.php:111]
- **`max_retries` sur agent non-mandatory silencieusement ignoré** — Un agent avec `max_retries: 2` mais sans `mandatory: true` ne retrye pas. Intentionnel par spec. Envisager un warning dans `YamlService` si `max_retries > 0` et `mandatory` absent.

## Deferred from: code review of 3-1-checkpoint-step-level-ecriture-et-lecture (2026-04-09)

- **`sanitizeEnvCredentials` dupliquée** — Logique identique dans `ArtifactService` et `CheckpointService`. À extraire dans un trait ou une classe utilitaire partagée lors d'un refactor futur. [CheckpointService.php:47, ArtifactService.php:85]
- **`checkpointService->write()` failure non gérée** — `File::put()` peut lever une exception (disk full, permissions) non catchée. Même pattern pre-existing dans `ArtifactService`. À adresser lors d'un hardening global des I/O filesystem. [CheckpointService.php:write()]
- **`RunCancelledException` laisse un checkpoint stale** — Si l'annulation survient au début de l'itération (après checkpoint pré-agent écrit), le dernier checkpoint pointe un agent comme `currentAgent` qui a peut-être déjà été complété. Story 3.4 (retry) devra gérer ce cas. [RunService.php:~47]
- **Regex `sanitizeEnvCredentials` incomplète** — Ne couvre pas les conventions `GITHUB_PAT`, `STRIPE_SK`, `DB_PASS`. Pre-existing, partagé avec `ArtifactService`. À élargir lors d'un audit sécurité global. [CheckpointService.php, ArtifactService.php]
- **`resolveSystemPrompt` silencieux sur fichier absent** — Retourne `''` sans log ni erreur si le fichier `system_prompt_file` n'existe pas. Pre-existing. [RunService.php:resolveSystemPrompt()]

## Deferred from: code review of 3-3-alerte-d-erreur-localisee-et-bubblebox-error (2026-04-10)

- **`AgentDiagram` silent failure sans workflow** — Quand `selectedWorkflow` est null, le diagramme rend un conteneur 400px vide sans feedback utilisateur. Pre-existing depuis 1.5. [AgentDiagram.tsx]
- **`StepItem` status toujours `"pending"`** — Tous les steps s'affichent comme pending indépendamment de l'avancement réel de l'agent. Granularité step-level prévue après Epic 3. Pre-existing depuis 1.5. [AgentCard.tsx]
- **Performance `useMemo` sur `agents`** — Le memo recalcule tous les nodes/edges à chaque mise à jour du store `agents` (statut, bubble, error). Impact minimal (≤5 agents, localhost). Pre-existing depuis 2.6. [AgentDiagram.tsx]
- **Badge état sans accessibilité ARIA** — `Badge` affiche le statut visuellement mais sans `role="status"` ni `aria-live` — changements d'état non annoncés aux lecteurs d'écran. Pre-existing depuis 1.5. [AgentCard.tsx]
- **`RunErrorEvent.agentId` non validé dans le parser** — Le type TypeScript déclare `agentId: string` comme requis mais `parseRunError` ne valide pas ce champ. Le listener a un guard `if (payload.agentId)` qui masque la divergence. Pre-existing depuis 2.4/2.5. [sseEventParser.ts, sse.types.ts]

## Deferred from: code review of 4-1-liste-des-runs-passes (2026-04-12)

- **`agentCount = 0` pour les runs annulés via `execute()`** — `completedAgents` est local à `executeAgents()` et n'est pas accessible depuis le `catch (RunCancelledException)` dans `execute()`. Le count réel des agents complétés est perdu. Impact faible (historique montre 0 agents pour les runs annulés). Refactorer si une précision accrue est nécessaire. [RunService.php:43]
- **Pas de pagination sur `File::directories()`** — `RunController::index()` charge l'intégralité du dossier runs/ à chaque requête. Performance acceptable pour MVP localhost. À adresser par offset/limit si l'historique devient volumineux. [RunController.php:30]
- **`finalizeRun()` peut masquer l'exception originale** — Dans les blocs catch de `executeAgents()`, si `finalizeRun()` lève une exception (disque plein, permissions), elle remplace l'exception d'origine. Pattern pré-existant dans le projet (pas de wrapping d'exceptions I/O). [RunService.php:189,207,225,258]

## Deferred from: code review of 3-4-retry-manuel-depuis-le-dernier-checkpoint (2026-04-11)

- **`retryStep` sans garde contre run encore actif** — `RunController::retryStep()` n'empêche pas un retry si `run:{id}` est toujours en cache (run en cours). Par design : le bouton Retry n'est visible que si le run est en état error côté UI. Pas de double-protection serveur. [RunController.php]
- **`checkpoint['runId']` non validé contre l'URL `$id`** — Le checkpoint lu depuis le disque contient un `runId` non comparé à l'URL `$id`. En pratique impossible (checkpoint toujours écrit avec le bon runId), mais pas de validation défensive. [RunController.php]
- **`retry_checkpoint` reste 3600s si le client ne reconnecte jamais** — Après POST `/retry-step`, si l'utilisateur ferme le navigateur, le checkpoint reste en cache 1h. Toute reconnexion ultérieure déclencherait un retry automatique non sollicité. Compromis TTL acceptable pour MVP. [RunController.php, SseController.php]

## Deferred from: code review of fix-ob-flush-workflow-launch (2026-04-12)

- **Proxies non-standard ignorant `X-Accel-Buffering: no`** — HAProxy, AWS ALB et équivalents ne reconnaissent pas ce header nginx-spécifique. Sans buffer utilisateur actif côté PHP, `flush()` seul envoie au worker PHP, mais le proxy peut encore bufferiser. Non causé par ce fix (pré-existant). À traiter si le projet est déployé derrière un tel proxy : ajouter `Transfer-Encoding: chunked` ou un `ob_start()` + flush explicite côté `SseController`. [SseController.php]

## Deferred from: code review of 4-2-selection-engine-par-agent (2026-04-12)

- **`InvalidArgumentException` de `DriverResolver::for()` non catchée dans `executeAgents()`** — Si un engine invalide atteint le resolver (ex : appel direct sans validation préalable, ou YAML modifié entre validation et exécution), l'exception bubble sans poser `error_emitted`, sans appeler `finalizeRun()`, et sans écrire de checkpoint. SseController le catchera via son fallback `\Throwable` et émettra un `RunError(agentId: 'unknown')`. Acceptable pour MVP (validation YamlService prévient le cas normal). [DriverResolver.php, RunService.php]
- **Whitelist engine dupliquée** — `['claude-code', 'gemini-cli']` définie dans `YamlService::validate()` ET implicitement dans `DriverResolver::for()`. Un ajout d'engine nécessite deux modifications synchronisées. Extraire en constante partagée si un troisième engine est introduit. [YamlService.php:91, DriverResolver.php]
- **`GeminiDriver --output-format json` nécessite Gemini CLI récent** — Le flag `--output-format` n'est disponible que dans les versions récentes de Gemini CLI. Un environnement avec une version ancienne échouera silencieusement (exit code non nul, stderr "unknown flag"). À documenter dans les prérequis de déploiement. [GeminiDriver.php]
- **`$startedAt` undefined dans le `catch (RunCancelledException)` de `executeFromCheckpoint`** — `$startedAt` est assigné à l'intérieur du `try` (ligne 82) mais référencé dans le `catch` (ligne 88). PHP émettra une `undefined variable` si une `RunCancelledException` est levée avant la ligne 82. Pré-existant à cette story. [RunService.php:88]

## Deferred from: code review of agent-skip-conditionnel (2026-04-14)

- **Skip signal non persisté en checkpoint** — Si le process crashe après qu'un agent a émis `skip_next` mais avant que l'agent suivant soit traité, le signal est perdu. Sur reprise depuis checkpoint, l'agent skippable s'exécutera normalement au lieu d'être sauté. Non causé par ce changement (gap architectural pré-existant du CheckpointService). À adresser si la persistance stricte des skip est requise. [RunService.php:executeAgents()]
- **LLM-controlled skip = surface d'injection de contrôle** — Un LLM compromis ou hallucinating peut émettre `next_action: "skip_next"` pour sauter un agent skippable sans raison légitime. Atténuation existante : seuls les agents explicitement marqués `skippable: true` sont concernés. À documenter dans les guidelines sécurité si le projet expose des workflows à des utilisateurs non-auteurs des YAML. [RunService.php:245-250]
