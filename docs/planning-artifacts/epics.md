---
stepsCompleted: [step-01-validate-prerequisites, step-02-design-epics, step-03-create-stories, step-04-final-validation]
status: complete
completedAt: '2026-04-03'
inputDocuments:
  - docs/planning-artifacts/prd.md
  - docs/planning-artifacts/architecture.md
  - docs/planning-artifacts/ux-design-specification.md
---

# xu-workflow - Epic Breakdown

## Overview

This document provides the complete epic and story breakdown for xu-workflow, decomposing the requirements from the PRD, UX Design if it exists, and Architecture requirements into implementable stories.

## Requirements Inventory

### Functional Requirements

FR1: L'utilisateur peut sélectionner un workflow parmi les fichiers YAML disponibles dans le dossier `workflows/`
FR2: Le système affiche le champ `name` défini dans le YAML comme libellé du workflow dans le sélecteur
FR3: L'utilisateur peut recharger manuellement la liste des workflows sans redémarrer l'application
FR4: Le système valide la structure d'un fichier YAML avant d'accepter le lancement d'un run
FR5: L'utilisateur peut visualiser le diagramme des agents d'un workflow sélectionné avant de lancer un run
FR6: Le système configure chaque agent (nom, engine, tâches, timeouts, system prompt) depuis les champs du YAML
FR7: L'utilisateur peut lancer un run en soumettant un brief textuel libre
FR8: Le système exécute les agents dans l'ordre séquentiel défini dans le YAML
FR9: Le système spawn un processus CLI (Claude Code ou Gemini CLI) par agent selon le champ `engine` du YAML, depuis le `project_path` défini
FR10: Le système injecte le system prompt de l'agent (inline ou via fichier externe référencé par `system_prompt_file`) à chaque invocation CLI
FR11: Le système applique un timeout configurable par tâche et interrompt le processus CLI en cas de dépassement
FR12: Le système retente automatiquement une étape marquée `mandatory: true` après échec, dans la limite de `max_retries`
FR13: L'utilisateur peut annuler un run en cours
FR14: Le système maintient un fichier de contexte partagé (cycle `.md`) mis à jour après chaque étape
FR15: Chaque agent reçoit le fichier de contexte partagé en entrée, en plus de son brief de tâche
FR16: Le système capture et parse la sortie JSON structurée de chaque agent (`{ step, status, output, next_action, errors }`)
FR17: Le système transmet l'output JSON d'un agent comme entrée de contexte à l'agent suivant
FR18: L'utilisateur peut voir l'état de chaque agent (idle / working / error / done) dans le diagramme, mis à jour en temps réel
FR19: Le diagramme anime la transition entre agents lors d'un handoff
FR20: L'utilisateur reçoit des notifications de progression sous forme de bulles associées à l'agent actif
FR21: L'utilisateur peut consulter le log complet de la session en cours dans une sidebar append-only, mise à jour en temps réel
FR22: L'utilisateur reçoit une alerte visuelle localisée sur le nœud concerné avec le détail de l'erreur en cas d'échec d'un agent
FR23: L'utilisateur voit un récapitulatif de fin de run (agents exécutés, durée totale, statut, lien vers le dossier)
FR24: Le système sauvegarde un checkpoint après chaque étape complétée avec succès
FR25: L'utilisateur peut relancer une étape en erreur depuis le dernier checkpoint sans rejouer les étapes précédentes
FR26: Le système libère proprement les ressources d'un processus CLI interrompu (timeout ou annulation)
FR27: Le système expose le message d'erreur et l'étape concernée dans l'alerte visible par l'utilisateur
FR28: Le système crée un dossier de run daté (`runs/YYYY-MM-DD-HHMM/`) pour chaque exécution
FR29: Le système génère et maintient un fichier `session.md` append-only contenant la trace complète du run
FR30: Le système sauvegarde les outputs de chaque agent dans le dossier run
FR31: L'utilisateur peut accéder à la liste des runs passés depuis l'interface

### NonFunctional Requirements

NFR1: La latence entre un événement agent (changement d'état, bulle) et son affichage dans l'UI ne dépasse pas 200ms en conditions localhost
NFR2: Le spawn d'un processus CLI et sa première sortie capturée interviennent en moins de 5 secondes
NFR3: Le rendu du diagramme (jusqu'à 5 nœuds actifs) reste fluide sans dégradation visible
NFR4: Aucun processus CLI ne reste en état zombie après un timeout, une annulation ou une erreur — le moteur nettoie les ressources dans tous les cas
NFR5: Le client SSE tente une reconnexion automatique en cas de déconnexion pendant un run actif
NFR6: Un checkpoint est écrit sur disque avant qu'une étape soit marquée complétée — aucune perte de progression en cas de crash Laravel
NFR7: Si la sortie d'un agent n'est pas du JSON valide, l'erreur est capturée et exposée — le moteur ne crashe pas silencieusement
NFR8: Le moteur supporte `claude-code` (flag `-p`) et `gemini-cli` (flag `-p`) comme engines headless interchangeables
NFR9: Le contrat de sortie JSON (`{ step, status, output, next_action, errors }`) est validé structurellement à chaque réponse agent avant traitement
NFR10: Une modification de la syntaxe CLI headless ne nécessite qu'un changement dans la couche driver, sans toucher le moteur core
NFR11: Les processus CLI spawned s'exécutent exclusivement depuis le `project_path` défini dans le YAML
NFR12: Les credentials, clés API et tokens présents dans l'environnement ne sont jamais logués dans `session.md` ou les artefacts de run

### Additional Requirements

- **Starter template** : Initialiser le frontend avec `npx create-next-app@latest frontend --typescript --tailwind --eslint --no-git` (Next.js 16.2.2) et le backend avec `laravel new backend --no-interaction` (Laravel 13.1.1) — constitue la première story d'implémentation
- **Process Facade Laravel** : Utiliser exclusivement le Process Facade natif pour le spawn CLI, capture stdout, timeout/kill — aucune lib externe
- **Driver layer isolé** : `DriverInterface` avec `execute()` et `kill()`, injectée via DI Container — jamais les implémentations concrètes (`ClaudeDriver`, `GeminiDriver`) directement dans les Services
- **Checkpoint write-before-complete** : Le checkpoint.json doit être écrit sur disque AVANT de marquer une étape comme `completed` (NFR6)
- **Laravel Resources camelCase** : La transformation camelCase se fait uniquement dans les Resources (RunResource, AgentResource, WorkflowResource) — jamais dans les Controllers ou Services
- **API sans wrapper** : `JsonResource::withoutWrapping()` dans AppServiceProvider — toutes les Resources retournent directement l'objet, sans clé `data`
- **Format d'erreur uniforme** : `{ "message": "Texte lisible", "code": "CODE_ENUM" }` — HTTP 422 pour validation YAML, HTTP 500 pour erreurs CLI
- **Dates ISO 8601** : Format `"2026-04-02T14:30:00Z"` partout dans les payloads API et SSE — jamais de timestamps Unix
- **SSE via EventSystem** : Les événements SSE s'émettent toujours via `event(new EventClass(...))` — jamais directement dans un Controller
- **useSSEListener hook** : Consommation SSE côté Next.js toujours via le hook custom — jamais d'`EventSource` instancié directement dans un composant
- **Zustand immuable** : Mises à jour Zustand via spread (`{ ...state, ... }`) — mutation directe de l'état interdite
- **Stores Zustand état seul** : Les stores ne contiennent que l'état et les setters — toute logique de transformation dans les hooks ou `lib/`
- **Sanitisation env** : Variables d'environnement sanitisées avant tout appel `Storage::put()` (NFR12)
- **Structure dossier run** : `runs/YYYY-MM-DD-HHMM/session.md + checkpoint.json + agents/{agent-id}.md`
- **Schema checkpoint.json** : `{ runId, workflowFile, brief, completedAgents[], currentAgent, currentStep, context }`
- **4 types d'événements SSE** : `agent.status.changed`, `agent.bubble`, `run.completed`, `run.error` — payloads en camelCase
- **Next.js proxy** : `next.config.ts` proxy `/api/*` → `localhost:8000` — pas d'appels directs au backend depuis le browser
- **Controllers sans logique métier** : Les Controllers Laravel orchestrent uniquement (appels Services + retour Resources) — aucune logique métier dans les Controllers

### UX Design Requirements

UX-DR1: Implémenter le composant `AgentCard` (base `Card` shadcn) avec header (nom + engine + Badge état), corps (`StepList`), footer conditionnel (`BubbleBox`), et 4 états visuels distincts : `idle` (opacité 45%), `working` (border blue-500, box-shadow), `done` (border emerald-500, opacité 70%), `error` (border red-500, animate-pulse)
UX-DR2: Implémenter le composant `StepItem` avec icône état (✓/⚙/○/✗), libellé, durée optionnelle, et 4 états : pending, working, done, error
UX-DR3: Implémenter le composant `PipelineConnector` (base `Separator` vertical shadcn) avec 3 états : `inactive` (zinc-700), `active` (blue-400), `done` (emerald-500), et transition `background-color` 300ms au changement d'état
UX-DR4: Implémenter le composant `BubbleBox` avec 3 variantes : `info` (bleu, étape en cours), `error` (rouge, message + bouton Retry ghost sm), `success` (vert, complétion), affichée inline sous la card active
UX-DR5: Implémenter le composant `RunSummaryModal` (base `Dialog` shadcn) avec stats globales (nb agents, durée, statut), liste agents avec statut individuel, et lien vers dossier run — auto-déclenché à la fin du run, fermable via Escape ou ×
UX-DR6: Implémenter le composant `LaunchBar` fixé en bas (`fixed bottom-0`, ~80px) avec `Textarea` (resize=none, placeholder défini) + `Button`, 3 états : `ready`, `running` (textarea disabled, bouton Annuler destructif actif), `disabled` (aucun workflow sélectionné)
UX-DR7: Définir les tokens couleur sémantiques dans `tailwind.config` : `agent-idle` → zinc-500, `agent-working` → blue-500, `agent-done` → emerald-500, `agent-error` → red-500 — utilisés systématiquement pour tous les états d'agents
UX-DR8: Appliquer le système de couleurs dark mode : background `zinc-950`/`zinc-900`, surface `zinc-800`, border `zinc-700`, edge active `blue-400`, text primary `zinc-100`, text secondary `zinc-400`
UX-DR9: Implémenter le layout 2 colonnes : zone diagramme `flex-1` (max-w-2xl centré) + sidebar log `w-80`, avec masquage de la sidebar en dessous de 1024px (diagramme pleine largeur)
UX-DR10: Implémenter la topbar avec sélecteur de workflow (`Select` shadcn), bouton Recharger (outline), et indicateur de run actif — SPA mono-vue sans routing entre pages
UX-DR11: Assurer le contraste WCAG AA (ratio ≥ 4.5:1) sur les textes principaux sur fond dark, et combiner couleur + badge texte pour tous les états agents (jamais la couleur seule comme unique signal)
UX-DR12: Navigation clavier fonctionnelle sur tous les éléments interactifs (Tab order : topbar → diagramme → sidebar → LaunchBar), focus visible (shadcn par défaut), ARIA labels sur les boutons sans texte explicite (ex : bouton Retry associé à l'agent concerné)

### FR Coverage Map

FR1: Epic 1 — Sélection d'un workflow YAML dans le sélecteur
FR2: Epic 1 — Affichage du champ `name` YAML dans le sélecteur
FR3: Epic 1 — Rechargement manuel de la liste des workflows
FR4: Epic 1 — Validation de la structure YAML avant lancement
FR5: Epic 1 — Visualisation du diagramme d'agents avant run
FR6: Epic 1 — Configuration des agents depuis les champs YAML
FR7: Epic 2 — Lancement d'un run avec brief textuel (Story 2.7a)
FR8: Epic 2 — Exécution séquentielle des agents selon l'ordre YAML
FR9: Epic 2 — Spawn CLI par agent selon le champ `engine`
FR10: Epic 2 — Injection du system prompt à chaque invocation CLI
FR11: Epic 2 — Timeout configurable par tâche avec interruption CLI
FR12: Epic 3 — Retry automatique des étapes `mandatory: true`
FR13: Epic 2 — Annulation d'un run en cours (Story 2.7a)
FR14: Epic 2 — Fichier de contexte partagé mis à jour après chaque étape
FR15: Epic 2 — Injection du contexte partagé en entrée de chaque agent
FR16: Epic 2 — Capture et parse de la sortie JSON structurée
FR17: Epic 2 — Propagation output JSON vers l'agent suivant
FR18: Epic 2 — États agents en temps réel dans le diagramme
FR19: Epic 2 — Animation de transition lors d'un handoff
FR20: Epic 2 — Bulles de progression associées à l'agent actif
FR21: Epic 2 — Sidebar append-only de log de session en temps réel
FR22: Epic 3 — Alerte visuelle localisée sur le nœud en erreur
FR23: Epic 2 — Modal récapitulatif de fin de run
FR24: Epic 3 — Sauvegarde checkpoint après chaque étape complétée
FR25: Epic 3 — Relance depuis dernier checkpoint sans rejouer les étapes précédentes
FR26: Epic 3 — Libération propre des ressources CLI interrompues
FR27: Epic 3 — Exposition message d'erreur + étape dans l'alerte
FR28: Epic 2 — Création dossier run daté `runs/YYYY-MM-DD-HHMM/`
FR29: Epic 2 — Génération et maintien du fichier `session.md` append-only
FR30: Epic 2 — Sauvegarde des outputs de chaque agent dans le dossier run
FR31: Epic 4 — Accès à la liste des runs passés depuis l'interface

## Epic List

### Epic 1: Visualiser et configurer son équipe d'agents

L'utilisateur peut charger des workflows YAML depuis le filesystem, les parcourir dans un sélecteur, et voir son équipe d'agents dans un diagramme statique — sans encore lancer quoi que ce soit.

**FRs couverts :** FR1, FR2, FR3, FR4, FR5, FR6
**NFRs :** NFR3 (rendu diagramme fluide), NFR10 (couche driver isolée dès le setup)
**UX-DRs :** UX-DR1, UX-DR2, UX-DR3, UX-DR7, UX-DR8, UX-DR9, UX-DR10

### Story 1.1 : Initialisation des projets Next.js et Laravel

As a développeur,
I want avoir les projets Next.js et Laravel initialisés avec leurs starters et configurés pour communiquer,
So that j'ai la base technique pour construire xu-workflow.

**Acceptance Criteria :**

**Given** un poste de développement avec Node.js, PHP 8.3+ et Composer installés
**When** les commandes d'initialisation sont exécutées (`create-next-app`, `laravel new`)
**Then** le projet `frontend/` existe avec TypeScript, Tailwind CSS et ESLint configurés
**And** le projet `backend/` existe avec Laravel 13.1.1 opérationnel
**And** `next.config.ts` proxifie `/api/*` vers `localhost:8000`
**And** `php artisan serve` (port 8000) et `npm run dev` (port 3000) démarrent sans erreur
**And** la structure de dossiers correspond à celle définie dans l'Architecture (`src/app/`, `src/components/`, `src/stores/`, `src/hooks/`, `src/types/`, `src/lib/`, `app/Http/Controllers/`, `app/Services/`, `app/Drivers/`)
**And** `JsonResource::withoutWrapping()` est configuré dans `AppServiceProvider`

---

### Story 1.2 : Design system — tokens couleur, dark mode et layout principal

As a développeur,
I want un design system dark mode avec les tokens couleur sémantiques et le layout 2 colonnes,
So that tous les composants UI qui suivent utilisent des fondations visuelles cohérentes.

**Acceptance Criteria :**

**Given** le projet frontend initialisé
**When** les tokens et le layout sont configurés
**Then** `tailwind.config.ts` expose les tokens `agent-idle` (zinc-500), `agent-working` (blue-500), `agent-done` (emerald-500), `agent-error` (red-500)
**And** la palette dark mode est appliquée globalement : background `zinc-950`/`zinc-900`, surface `zinc-800`, border `zinc-700`, text primary `zinc-100`, text secondary `zinc-400`
**And** le layout principal `page.tsx` affiche 2 colonnes : zone diagramme `flex-1` + sidebar `w-80`
**And** la sidebar est masquée en dessous de `lg` (1024px), le diagramme prend alors toute la largeur
**And** shadcn/ui est installé et les composants `Card`, `Badge`, `Button`, `Select`, `Separator`, `ScrollArea` sont disponibles
**And** le contraste WCAG AA (≥ 4.5:1) est respecté sur les textes principaux sur fond dark

---

### Story 1.3 : API Laravel — chargement, validation et exposition des workflows YAML

As a développeur,
I want une API Laravel qui charge, valide et expose les fichiers YAML du dossier `workflows/`,
So that le frontend peut récupérer la liste des workflows et leurs configurations d'agents.

**Acceptance Criteria :**

**Given** un ou plusieurs fichiers YAML dans le dossier `workflows/`
**When** `GET /api/workflows` est appelé
**Then** la réponse retourne un tableau JSON (sans wrapper `data`) listant les workflows avec leur `name`, `file`, et la liste des agents avec `id`, `engine`, `timeout`
**And** les champs sont en camelCase (transformation dans `WorkflowResource` uniquement)
**When** un YAML malformé ou manquant d'un champ obligatoire est présent
**Then** ce workflow est exclu de la liste (ou une erreur `{ "message": "...", "code": "YAML_INVALID" }` est retournée si demandé explicitement)
**And** `YamlService` centralise le chargement depuis `workflows/*.yaml`
**And** `DriverInterface` avec les méthodes `execute(string $prompt, array $options): string` et `kill(int $pid): void` est scaffoldée dans `app/Drivers/` (NFR10)
**And** les squelettes `ClaudeDriver` et `GeminiDriver` implémentant `DriverInterface` existent avec un corps `// TODO Epic 2 - Story 2.1` dans les méthodes `execute()` et `kill()` — les méthodes lèvent `\RuntimeException('Not implemented')` en attendant (NFR10)

---

### Story 1.4 : Sélecteur de workflow et rechargement dynamique

As a développeur,
I want un sélecteur de workflow dans la topbar avec rechargement manuel,
So that je peux changer de configuration YAML sans redémarrer l'application.

**Acceptance Criteria :**

**Given** l'API `/api/workflows` opérationnelle
**When** la page se charge
**Then** le `Select` shadcn dans la topbar liste les workflows disponibles avec leur `name` comme libellé (FR2)
**When** je sélectionne un workflow dans le dropdown
**Then** `workflowStore` est mis à jour avec la configuration du workflow sélectionné (agents, engine, timeouts)
**When** je clique "Recharger" (bouton outline dans la topbar)
**Then** `GET /api/workflows` est rappelé et le dropdown se met à jour avec les fichiers YAML actuels du filesystem (FR3)
**And** pendant le rechargement, le `Select` est disabled et le bouton Recharger affiche un spinner
**And** si aucun workflow n'est sélectionné, la zone diagramme affiche un message centré invitant à sélectionner un workflow
**And** la topbar est fixée en haut, la SPA n'a pas de routing entre pages

---

### Story 1.5 : Diagramme statique des agents d'un workflow sélectionné

As a développeur,
I want voir les agents du workflow sélectionné dans un pipeline vertical de cards avec leur état `idle`,
So that je visualise mon équipe avant de lancer un run.

**Acceptance Criteria :**

**Given** un workflow sélectionné dans le sélecteur
**When** le diagramme se charge
**Then** le composant `<ReactFlow>` est monté avec les nodes et edges générés depuis la config workflow du `workflowStore`
**And** chaque agent est rendu comme un custom node React Flow de type `agentCard` — le composant `AgentCard` (Card shadcn) reçoit `name`, `engine`, `steps[]` et `status: "idle"` via `data` props (FR5, UX-DR1)
**And** chaque `AgentCard` affiche un `Badge` `idle` (zinc-500, opacité 45%) et liste ses étapes sous forme de `StepItem` (icône ○, statut `pending`) (UX-DR2)
**And** les agents sont reliés par des custom edges React Flow de type `pipelineConnector` en état `inactive` (zinc-700) (UX-DR3)
**And** React Flow est configuré avec `fitView`, interactions désactivées (`nodesDraggable={false}`, `nodesConnectable={false}`, `panOnDrag={false}`, `zoomOnScroll={false}`) — pipeline read-only
**When** je change de workflow dans le sélecteur
**Then** les `nodes` et `edges` du `<ReactFlow>` sont recalculés depuis le nouveau YAML — le diagramme se reconfigure instantanément (FR5)
**And** les cards sont max-width `2xl`, centrées via `fitView`
**And** le rendu reste fluide jusqu'à 5 agents affichés simultanément (NFR3)

---

### Epic 2: Lancer et observer un run complet

L'utilisateur peut lancer un run avec un brief textuel et observer en temps réel l'exécution séquentielle des agents jusqu'à la complétion — diagramme animé, bulles SSE, sidebar de log, modal de fin et artefacts créés.

**FRs couverts :** FR7, FR8, FR9, FR10, FR11, FR13, FR14, FR15, FR16, FR17, FR18, FR19, FR20, FR21, FR23, FR28, FR29, FR30
**NFRs :** NFR1, NFR2, NFR3, NFR4, NFR5, NFR7, NFR8, NFR9, NFR11, NFR12
**UX-DRs :** UX-DR4 (BubbleBox info/success), UX-DR5, UX-DR6, UX-DR11, UX-DR12

### Story 2.1 : Moteur d'exécution Laravel — spawn CLI séquentiel et contrat JSON

As a développeur,
I want que Laravel spawne les agents CLI en séquence et capture leur sortie JSON structurée,
So that le pipeline d'agents s'exécute de bout en bout sans intervention humaine.

**Acceptance Criteria :**

**Given** un workflow YAML valide avec N agents séquentiels
**When** `POST /api/runs { workflowFile, brief }` est appelé
**Then** Laravel crée un `runId` (UUID), initialise un `RunResource` et démarre le premier agent
**And** chaque agent est spawné via `DriverInterface::execute()` depuis le `project_path` du YAML (NFR11)
**And** `ClaudeDriver` appelle `claude -p` avec `--output-format json`, `--allowedTools` et `--append-system-prompt` ; `GeminiDriver` appelle `gemini -p` avec `--yolo`
**And** le system prompt est injecté depuis le champ inline ou depuis le fichier `system_prompt_file` (FR10)
**And** la sortie stdout de chaque agent est parsée comme JSON `{ step, status, output, next_action, errors }` et validée structurellement (NFR9)
**And** si le JSON est invalide, l'erreur est capturée et exposée — le moteur ne crashe pas silencieusement (NFR7)
**And** l'output JSON d'un agent est transmis comme contexte d'entrée à l'agent suivant (FR17)
**And** `RunService` injecte `DriverInterface` via le Service Container — jamais `ClaudeDriver` directement

---

### Story 2.2 : Timeout par tâche et annulation de run

As a développeur,
I want que chaque agent soit interrompu automatiquement après son timeout et que je puisse annuler un run en cours,
So that aucun run ne bloque indéfiniment et je garde le contrôle.

**Acceptance Criteria :**

**Given** un agent en cours d'exécution avec un `timeout` défini dans le YAML
**When** le processus CLI dépasse la durée configurée
**Then** Laravel interrompt le processus proprement via `DriverInterface::kill()` (FR11)
**And** aucun processus CLI ne reste en état zombie après l'interruption (NFR4)
**When** `DELETE /api/runs/{id}` est appelé pendant un run actif
**Then** le processus CLI en cours est interrompu, les ressources sont libérées (FR13, FR26)
**And** l'état du run passe à `cancelled` dans le `runStore`
**And** le spawn du premier agent et sa première sortie capturée interviennent en moins de 5 secondes (NFR2)

---

### Story 2.3 : Contexte partagé inter-agents et artefacts de run

As a développeur,
I want que chaque agent reçoive le contexte partagé et que les artefacts soient créés au fil du run,
So that chaque agent dispose de l'information nécessaire et le run est traçable.

**Acceptance Criteria :**

**Given** un run démarré
**When** le dossier run est créé
**Then** `ArtifactService` crée `runs/YYYY-MM-DD-HHMM/session.md`, `checkpoint.json` et le dossier `agents/` (FR28)
**And** le fichier de contexte partagé (cycle `.md`) est mis à jour après chaque étape avec l'output de l'agent (FR14)
**And** chaque agent reçoit le fichier de contexte partagé en entrée en plus de son brief de tâche (FR15)
**And** l'output de chaque agent complété est sauvegardé dans `agents/{agent-id}.md` (FR30)
**And** `session.md` est enrichi en mode append-only après chaque étape (FR29)
**And** les variables d'environnement sont sanitisées avant tout appel `Storage::put()` — aucun credential logué (NFR12)

---

### Story 2.4 : Stream SSE — événements temps réel Laravel → Next.js

As a développeur,
I want recevoir en temps réel les événements du run via SSE,
So that le frontend peut mettre à jour le diagramme et les bulles sans polling.

**Acceptance Criteria :**

**Given** un run démarré via `POST /api/runs`
**When** le client ouvre `GET /api/runs/{id}/stream`
**Then** le stream SSE reste ouvert pendant toute la durée du run
**And** Laravel émet les 4 types d'événements normalisés via `event(new ...)` : `agent.status.changed`, `agent.bubble`, `run.completed`, `run.error`
**And** tous les payloads SSE sont en camelCase avec timestamps ISO 8601
**And** le stream se ferme automatiquement à `run.completed` ou `run.error`
**And** la latence entre un événement agent et son émission SSE est inférieure à 200ms en conditions localhost (NFR1)

---

### Story 2.5 : Client SSE et mise à jour des stores Zustand

As a développeur,
I want que le hook `useSSEListener` consomme les événements SSE et mette à jour les stores Zustand,
So that le diagramme et les bulles réagissent en temps réel sans action utilisateur.

**Acceptance Criteria :**

**Given** un run actif avec un stream SSE ouvert
**When** un événement `agent.status.changed` est reçu
**Then** `agentStatusStore` est mis à jour immédiatement (immutable spread) avec le nouvel état de l'agent
**When** la connexion SSE est perdue pendant un run actif
**Then** `useSSEListener` tente une reconnexion automatique via le retry natif de `EventSource` (NFR5)
**And** `useSSEListener` est le seul point de consommation SSE — aucun `EventSource` instancié directement dans un composant
**And** les mises à jour Zustand se font uniquement via spread immutable (`{ ...state, ... }`)
**And** aucune logique de transformation n'est dans les stores — uniquement dans les hooks ou `lib/`

---

### Story 2.6 : Diagramme animé — états temps réel et transitions de handoff

As a développeur,
I want que le diagramme se mette à jour en temps réel avec les états des agents et anime les handoffs,
So that je vois visuellement qui travaille, qui a fini, et quand le contexte passe d'un agent au suivant.

**Acceptance Criteria :**

**Given** un run actif avec des événements SSE qui arrivent
**When** un événement `agent.status.changed { status: "working" }` est reçu
**Then** `agentStatusStore` est mis à jour — React Flow re-rend le custom node `agentCard` de l'agent concerné avec l'état `working` (border blue-500, box-shadow) via le champ `data.status` du node (FR18, UX-DR1)
**When** un agent passe à `done`
**Then** son custom node `agentCard` affiche l'état `done` (border emerald-500, opacité 70%)
**And** le custom edge `pipelineConnector` suivant reçoit `data.status: "done"` — sa couleur passe à emerald-500 avec transition CSS 300ms (FR19, UX-DR3)
**And** le custom node de l'agent suivant reçoit `data.status: "working"` — la mise à jour des deux nodes via `useReactFlow().setNodes()` se fait dans le même tick pour une transition fluide
**When** tous les agents sont `done`
**Then** le diagramme reste dans son état final lisible, aucun spinner global ne subsiste
**And** React Flow n'est jamais re-monté pendant un run — seuls les `data` des nodes et edges sont mis à jour

---

### Story 2.7a : LaunchBar — lancement et annulation d'un run

As a développeur,
I want une barre de lancement fixée en bas de l'interface pour soumettre un brief et annuler un run en cours,
So that je peux déclencher et contrôler l'exécution sans quitter la vue principale.

**Acceptance Criteria :**

**Given** la page chargée sans workflow sélectionné
**When** la `LaunchBar` s'affiche
**Then** elle est en état `disabled` : `Textarea` et bouton "Lancer" sont disabled (UX-DR6)

**Given** un workflow sélectionné, aucun run actif
**When** la `LaunchBar` s'affiche
**Then** elle est en état `ready` : `Textarea` activé (placeholder : "Décris la tâche à confier à l'équipe..."), bouton "Lancer" primaire visible (UX-DR6)

**Given** la `LaunchBar` en état `ready`
**When** je clique "Lancer" avec un brief non-vide
**Then** `POST /api/runs { workflowFile, brief }` est appelé (FR7)
**And** la `LaunchBar` passe en état `running` : `Textarea` disabled, bouton "Lancer" remplacé par "Annuler" (destructif)
**And** le `runStore` est mis à jour avec le `runId` retourné

**Given** la `LaunchBar` en état `ready`
**When** je clique "Lancer" et que Laravel retourne HTTP 422 `{ message, code: "YAML_INVALID" }`
**Then** la `LaunchBar` reste en état `ready` — aucun run ne démarre (FR4)
**And** un message d'erreur inline apparaît dans la `LaunchBar`, au-dessus du `Textarea` : texte du champ `message` retourné par l'API
**And** le message disparaît dès que l'utilisateur sélectionne un autre workflow ou clique à nouveau "Lancer"

**Given** la `LaunchBar` en état `running`
**When** je clique "Annuler"
**Then** `DELETE /api/runs/{id}` est appelé (FR13)
**And** la `LaunchBar` repasse en état `ready`

**Given** la `LaunchBar` en état `running`
**When** un événement `run.completed` ou `run.error` est reçu via SSE
**Then** la `LaunchBar` repasse en état `ready` automatiquement

**And** la `LaunchBar` est fixée en bas (`fixed bottom-0`), hauteur ~80px, toujours visible
**And** le bouton "Lancer" est accessible au clavier, focus visible (UX-DR12)

---

### Story 2.7b : Bulles SSE, sidebar de log et modal de fin de run

As a développeur,
I want voir les bulles SSE sur l'agent actif, le log de session en temps réel dans la sidebar, et un récapitulatif à la fin du run,
So that j'ai une visibilité complète sur la progression et les résultats sans lire les logs bruts.

**Acceptance Criteria :**

**Given** un run actif
**When** un événement `agent.bubble` est reçu via SSE
**Then** une `BubbleBox` variante `info` apparaît inline sous la `AgentCard` active (custom node React Flow) avec le message de l'étape (FR20, UX-DR4)
**And** si une `BubbleBox` existait déjà sur cette card, elle est remplacée par la nouvelle

**Given** un run actif
**When** le stream SSE est ouvert
**Then** la sidebar s'enrichit en append-only via `GET /api/runs/{id}/log` — polling ou SSE — avec le contenu de `session.md` (FR21)
**And** la `ScrollArea` de la sidebar défile automatiquement vers le bas à chaque nouvel ajout

**Given** un run actif
**When** un événement `run.completed` est reçu
**Then** le `RunSummaryModal` (`Dialog` shadcn) s'ouvre automatiquement avec : nombre d'agents, durée totale, statut global, lien vers le dossier run (FR23, UX-DR5)
**And** la modal est fermable via `Escape` ou le bouton ×
**And** après fermeture, le diagramme reste dans son état final `done`

**And** tous les éléments interactifs (bouton ×, lien dossier) sont accessibles au clavier avec focus visible (UX-DR12)

---

### Epic 3: Résilience et récupération d'erreurs

L'utilisateur peut faire face aux échecs partiels — erreur localisée sur le nœud concerné, checkpoint step-level sauvegardé, relance depuis le bon point sans rejouer les étapes déjà complétées.

**FRs couverts :** FR12, FR22, FR24, FR25, FR26, FR27
**NFRs :** NFR4, NFR6, NFR7
**UX-DRs :** UX-DR4 (variante error + bouton Retry)

### Story 3.1 : Checkpoint step-level — écriture et lecture

As a développeur,
I want que le moteur écrive un checkpoint après chaque étape complétée et sache le relire,
So that le système peut reprendre un run depuis n'importe quel point sans recalcul.

**Acceptance Criteria :**

**Given** un run en cours avec un agent qui vient de compléter une étape
**When** l'étape est marquée complétée
**Then** `CheckpointService` écrit `checkpoint.json` sur disque avant de changer le statut de l'étape à `completed` (NFR6)
**And** le `checkpoint.json` contient : `{ runId, workflowFile, brief, completedAgents[], currentAgent, currentStep, context }`
**And** `CheckpointService` peut relire ce fichier et restituer l'état exact du run au moment du checkpoint
**And** si Laravel crashe entre deux étapes, le checkpoint de la dernière étape complétée est intact et utilisable

---

### Story 3.2 : Retry automatique des étapes `mandatory`

As a développeur,
I want que les étapes marquées `mandatory: true` soient automatiquement retentées en cas d'échec, dans la limite de `max_retries`,
So that les étapes critiques ont une chance de récupérer sans intervention manuelle.

**Acceptance Criteria :**

**Given** une étape avec `mandatory: true` et `max_retries: N` dans le YAML
**When** l'étape échoue (JSON invalide, erreur CLI, timeout)
**Then** `RunService` relance automatiquement l'étape depuis le dernier checkpoint (FR12)
**And** chaque retry décrémente le compteur restant
**When** `max_retries` est atteint sans succès
**Then** le moteur marque l'étape en `error` définitif et émet un événement `run.error`
**And** les retries automatiques n'émettent pas de `run.error` tant qu'il reste des tentatives disponibles — uniquement une `agent.bubble` info indiquant la tentative en cours

---

### Story 3.3 : Alerte d'erreur localisée et BubbleBox error

As a développeur,
I want voir l'erreur localisée sur le nœud concerné avec le message détaillé et un bouton Retry,
So that je sais exactement ce qui a planté et je peux agir immédiatement sans quitter la vue.

**Acceptance Criteria :**

**Given** un événement `run.error` reçu via SSE
**When** l'erreur est traitée par `useSSEListener`
**Then** la `AgentCard` de l'agent concerné passe à l'état `error` (border red-500, `animate-pulse`) (FR22, UX-DR1)
**And** une `BubbleBox` variante `error` apparaît inline sous la card : message d'erreur + étape concernée + bouton "Relancer cette étape" (ghost sm) (FR27, UX-DR4)
**And** les autres `AgentCard` restent dans leur dernier état connu — aucune perturbation des nœuds sains
**And** le message d'erreur est humainement lisible (format `{ message, code }` depuis Laravel) (FR27)
**And** la `LaunchBar` passe en état `ready` — le bouton "Annuler" disparaît

---

### Story 3.4 : Retry manuel depuis le dernier checkpoint

As a développeur,
I want pouvoir relancer une étape en erreur depuis le dernier checkpoint en un clic,
So that le workflow reprend exactement là où il s'est arrêté sans rejouer les étapes déjà complétées.

**Acceptance Criteria :**

**Given** une `BubbleBox` error visible avec un bouton "Relancer cette étape"
**When** je clique ce bouton
**Then** `POST /api/runs/{id}/retry-step` est appelé
**And** `CheckpointService` recharge le contexte jusqu'au dernier checkpoint valide (FR25)
**And** seule l'étape fautive est relancée — les étapes précédentes ne sont pas rejouées (FR25)
**And** la `AgentCard` de l'étape relancée repasse à l'état `working`
**And** la `BubbleBox` error est remplacée par une `BubbleBox` info avec le message de reprise
**And** si le retry réussit, le pipeline reprend normalement depuis cette étape
**And** les ressources du processus CLI interrompu sont libérées avant le relancement (FR26)

---

### Epic 4: Historique des runs

L'utilisateur peut accéder depuis l'interface à la liste des runs passés.

**FRs couverts :** FR31

### Story 4.1 : Liste des runs passés

As a développeur,
I want accéder depuis l'interface à la liste de mes runs passés avec leur statut et leurs informations clés,
So that je peux retrouver et consulter les artefacts d'un run précédent.

**Acceptance Criteria :**

**Given** des runs passés dans le dossier `runs/`
**When** `GET /api/runs` est appelé
**Then** la réponse retourne la liste des runs avec pour chacun : `runId`, `workflowFile`, `status`, `duration`, `agentCount`, `runFolder`, `createdAt` (ISO 8601)
**And** les runs sont triés par date décroissante (le plus récent en premier)

**Given** l'interface chargée
**When** je clique le bouton "Historique" dans la topbar (outline, à droite du bouton "Recharger")
**Then** un `Sheet` shadcn s'ouvre depuis la droite avec la liste des runs passés (FR31)
**And** chaque ligne affiche : nom du workflow, date (`createdAt` formatée), statut (Badge coloré : done/error/cancelled) et un lien vers le dossier run
**And** un clic sur le lien dossier affiche le chemin filesystem dans un `Tooltip` ou le copie dans le presse-papier
**And** si aucun run n'existe encore, le `Sheet` affiche un message vide "Aucun run pour l'instant"
**And** le `Sheet` se ferme via `Escape`, le bouton × ou un clic en dehors
**And** le `Sheet` est inaccessible (bouton disabled) pendant un run actif — les runs en cours ne sont pas dans l'historique
