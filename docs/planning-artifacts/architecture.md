---
stepsCompleted: [1, 2, 3, 4, 5, 6, 7, 8]
status: 'complete'
completedAt: '2026-04-03'
lastStep: 8
inputDocuments:
  - docs/planning-artifacts/prd.md
  - docs/planning-artifacts/research/technical-claude-gemini-cli-integration-research-2026-04-02.md
  - docs/brainstorming/brainstorming-session-2026-04-02-1000.md
workflowType: 'architecture'
project_name: 'XuMaestro'
user_name: 'Léo'
date: '2026-04-02'
---

# Architecture Decision Document

_Ce document se construit collaborativement, étape par étape. Les sections sont ajoutées au fil des décisions architecturales prises ensemble._

## Project Context Analysis

### Requirements Overview

**Functional Requirements — 31 FR en 6 catégories :**
- **Gestion des Workflows (FR1–FR6)** : sélecteur YAML, validation schema, rechargement manuel, diagramme pré-run, configuration agents depuis YAML
- **Exécution & Orchestration (FR7–FR13)** : lancement par brief, pipeline séquentiel, spawn CLI par engine, injection system prompt, timeout par tâche, retry mandatory, annulation
- **Communication inter-agents (FR14–FR17)** : contexte partagé fichier .md, contrat JSON structuré `{ step, status, output, next_action, errors }`, propagation output agent → agent suivant
- **Monitoring temps réel (FR18–FR23)** : états idle/working/error/done en temps réel, animations de transition, bulles SSE, sidebar append-only, alertes localisées sur nœud, modal récapitulatif de fin de run
- **Résilience (FR24–FR27)** : checkpoint step-level, retry depuis dernier checkpoint, libération propre des ressources, exposition message d'erreur + étape concernée
- **Artefacts & Traçabilité (FR28–FR31)** : dossier run daté, session.md append-only, outputs par agent, liste runs passés

**Non-Functional Requirements — 12 NFR :**
- **Performance** : latence SSE → UI < 200ms (NFR1), spawn CLI < 5s (NFR2), rendu diagramme fluide jusqu'à 5 nœuds (NFR3)
- **Fiabilité** : zéro zombie process (NFR4), reconnexion SSE auto (NFR5), checkpoint avant marquage `completed` (NFR6), échec JSON capturé sans crash silencieux (NFR7)
- **Intégration** : driver interchangeable claude-code / gemini-cli (NFR8), validation structurelle contrat JSON (NFR9), couche driver isolée (NFR10)
- **Sécurité** : exécution CLI depuis `project_path` uniquement (NFR11), aucun credential logué dans les artefacts (NFR12)

**Scale & Complexity :**
- Domaine primaire : Full-stack web local (Next.js SPA + Laravel API + gestion processus système)
- Complexité : Moyenne — usage local single-user, pas de multi-tenancy, pas de compliance
- Composantes architecturales estimées : ~8

### Technical Constraints & Dependencies

- **Claude Code headless** : flag `-p`, `--output-format json`, `--allowedTools` (pré-approbation des outils, zéro prompts bloquants), `--append-system-prompt` pour l'injection du system prompt, `--max-turns` pour limiter les tours
- **Gemini CLI headless** : flag `-p` ou détection TTY absente, `--yolo` pour auto-approve tous les tool calls — codes de sortie : 0=succès, 1=erreur API, 42=input error, 53=turn limit
- **Rate limits opaques** : quota Claude Max non requêtable programmatiquement (pas d'endpoint "quota restant") — seul garde-fou disponible en scripting : `--max-budget-usd`
- **SSE** : unidirectionnel uniquement, natif HTTP, aucun polyfill requis côté Chrome desktop — suffisant pour le flux Laravel → Next.js
- **Pas de base de données** : le filesystem est la source de vérité absolue (YAML, session.md, checkpoints, run folders, memory files)

### Cross-Cutting Concerns Identified

1. **Process lifecycle** — spawn, monitoring stdout, timeout déclenché, kill propre, cleanup ressources — traverse le moteur, la gestion d'erreurs, les checkpoints et le SSE
2. **Validation contrat JSON** — à chaque réponse agent, avant propagation vers l'agent suivant et vers l'UI
3. **Synchronisation d'état** — source de vérité côté Laravel, propagée en SSE vers Zustand stores Next.js — aucun polling, flux push uniquement
4. **I/O fichiers** — session.md, checkpoints, dossiers run, YAML, memory files — toutes les opérations critiques passent par le filesystem local
5. **Driver layer isolation** — abstraction des différences claude/gemini derrière une interface commune — seul point de changement si les CLIs modifient leur interface

## Starter Template Evaluation

### Primary Technology Domain

Full-stack web local — les deux applications tournent en localhost uniquement.
Stack pré-décidée dans la PRD, confirmée ici avec les versions actuelles.

### Starters Sélectionnés

**Frontend : Next.js 16.2.2 — `create-next-app`**
**Backend : Laravel 13.1.1 — `laravel new`**

Aucune alternative évaluée — la PRD spécifie explicitement cette combinaison et les deux écosystèmes fournissent nativement les mécanismes requis.

### Commandes d'Initialisation

```bash
# Frontend
npx create-next-app@latest frontend --typescript --tailwind --eslint --no-git

# Backend
laravel new backend --no-interaction
```

**Note :** L'initialisation des deux projets constitue la première story d'implémentation.

### Décisions Architecturales Fournies par les Starters

**Frontend (Next.js 16.2.2) :**
- Langage : TypeScript strict
- Rendu : App Router avec `'use client'` — client-side only, pas de SSR
- Styling : Tailwind CSS (utilitaire, aligné avec la cible Chrome desktop uniquement)
- Routing : file-based via `src/app/`
- Linting : ESLint + Prettier
- Build : mode dev server pour localhost (requis par le client SSE EventSource)

**Backend (Laravel 13.1.1) :**
- Langage : PHP 8.3+
- Process management : Process Facade natif — spawn CLI, capture stdout, timeout/kill (mécanisme exact requis par le moteur d'exécution, zéro lib externe)
- Event system : Event/Listener natif — base pour l'architecture SSE événementielle
- CLI : Artisan commands — opérations moteur, debug, maintenance
- DI : Service Container — isolation du driver layer CLI (NFR10)
- Middleware : validation YAML avant lancement de run (FR4)
- Filesystem : Storage facade — opérations sur dossiers run, session.md, checkpoints

## Core Architectural Decisions

### Decision Priority Analysis

**Décisions critiques (bloquent l'implémentation) :**
- Librairie de diagramme : React Flow
- Format événements SSE : 4 types d'événements normalisés
- Design API REST : resource-based avec Laravel Resources
- Structure checkpoints : checkpoint.json + dossier run daté

**Décisions déjà prises par la PRD :**
- Stack complète, Zustand, SSE, filesystem-only, contrat JSON, artefacts run

**Décisions différées (post-MVP) :**
- Migration diagramme → PixiJS canvas (Phase 3 pixel art)
- Cross-agent memory sharing, Review node, Bug Loop

### Data Architecture

Pas de base de données. Filesystem comme source de vérité absolue.

**Structure du dossier run :**

```
runs/YYYY-MM-DD-HHMM/
  session.md              ← log append-only complet du run
  checkpoint.json         ← état courant (resume point)
  agents/
    {agent-id}.md         ← output de chaque agent complété
```

**Schema checkpoint.json :**

```json
{
  "runId": "uuid",
  "workflowFile": "feature-dev.yaml",
  "brief": "...",
  "completedAgents": ["pm", "laravel-dev"],
  "currentAgent": "qa",
  "currentStep": 2,
  "context": "runs/2026-04-02-1430/session.md"
}
```

Checkpoint écrit sur disque **avant** marquage `completed` (NFR6).

**YAML workflows :** chargés depuis `workflows/*.yaml` au démarrage + bouton reload. Validation schema côté Laravel avant lancement (FR4). Aucun état YAML persisté en base.

### Authentication & Security

Pas d'authentification — usage localhost single-user.

**Sécurité périmètre local :**
- Exécution CLI depuis `project_path` défini dans le YAML uniquement (NFR11)
- Credentials/tokens de l'environnement jamais loggés dans session.md ni les artefacts (NFR12)
- Sanitisation des variables d'environnement avant écriture sur disque

### API & Communication Patterns

**REST resource-based :**

```
POST   /api/runs                  → créer + lancer un run
GET    /api/runs/{id}             → état du run (Laravel Resource)
GET    /api/runs/{id}/stream      → SSE stream (ouvert au lancement, fermé à completion/erreur)
DELETE /api/runs/{id}             → annuler le run
GET    /api/runs                  → liste des runs passés
GET    /api/workflows             → liste des YAML disponibles
POST   /api/runs/{id}/retry-step  → retry depuis dernier checkpoint
```

Laravel Resources pour le contrat JSON stable : `RunResource`, `AgentResource`, `StepResource`.

**Format événements SSE (4 types) :**

```json
{ "event": "agent.status.changed",
  "data": { "runId", "agentId", "status": "working|idle|error|done", "step", "message", "timestamp" }}

{ "event": "agent.bubble",
  "data": { "runId", "agentId", "message", "step", "timestamp" }}

{ "event": "run.completed",
  "data": { "runId", "duration", "agentCount", "status", "runFolder", "timestamp" }}

{ "event": "run.error",
  "data": { "runId", "agentId", "step", "message", "checkpointPath", "timestamp" }}
```

SSE stream : ouvert à `POST /api/runs`, fermé à `run.completed` ou `run.error`. Client Next.js : reconnexion automatique via `EventSource` avec retry natif (NFR5).

**Gestion d'erreurs :** Laravel capture toute exception process CLI, émet `run.error` via SSE et retourne HTTP 422 sur les erreurs de validation YAML. Jamais de crash silencieux (NFR7).

### Frontend Architecture

**Librairie diagramme : React Flow**
- Nœuds déclaratifs, arêtes animées, états idle/working/error/done par nœud
- Mis à jour directement par le listener SSE via Zustand `agentStatusStore`
- Migration Phase 3 (pixel art PixiJS) = swap de la couche rendu uniquement

**Zustand stores (3 stores dédiés) :**
- `workflowStore` — YAML actif, liste des workflows, configuration agents
- `runStore` — état du run courant (id, status, durée, dossier run)
- `agentStatusStore` — état par agent (idle/working/error/done), steps, messages bulles

Mis à jour **directement** par le listener SSE — aucun polling, aucune requête GET pendant un run actif.

**SSE client :** hook custom `useSSEListener(runId)` wrappant l'API native `EventSource`. Reconnexion automatique sur déconnexion pendant un run actif (NFR5).

### Infrastructure & Deployment

Localhost uniquement. Deux serveurs en développement :
- Next.js dev server : port 3000
- Laravel : port 8000 (via `php artisan serve`)

Next.js proxy via `next.config.ts` pour les appels API → `localhost:8000/api/*`. Pas de CI/CD, pas de containerisation pour le MVP.

### Decision Impact Analysis

**Séquence d'implémentation imposée par les dépendances :**
1. Init projets (Next.js + Laravel)
2. Moteur Laravel : YAML loader + validator + Process Facade driver
3. SSE stream Laravel → endpoint `/api/runs/{id}/stream`
4. Run creation API + checkpoint system
5. SSE client Next.js + Zustand stores
6. React Flow diagramme avec états
7. Dual channel : bulles SSE + sidebar session.md
8. Retry depuis checkpoint + gestion erreurs

**Dépendances transversales :**
- React Flow dépend de `agentStatusStore` (Zustand) qui dépend du listener SSE
- Checkpoint system doit être opérationnel avant d'implémenter le retry (FR25)
- Driver layer (NFR10) doit être isolé dès le début — toute la logique CLI derrière une `DriverInterface`

## Implementation Patterns & Consistency Rules

### Points de Conflit Identifiés : 5 catégories

### Naming Patterns

**JSON — Nommage des champs :**
- Laravel Resources : transformation `camelCase` via `->camelCase()` dans chaque Resource
- TypeScript : tous les types API utilisent le camelCase (`agentId`, `runFolder`, `checkpointPath`)
- PHP interne (Services, Models) : snake_case natif PHP/Laravel
- Règle : la transformation camelCase se fait **uniquement dans les Resources** — jamais dans les Controllers ou Services

**API REST — Nommage des endpoints :**
- Ressources au pluriel : `/api/runs`, `/api/workflows`
- Paramètres de route : `{id}` (Laravel style)
- Query params : camelCase (`workflowFile`, `agentId`)

**SSE Events — Nommage :**
- Format `domaine.action` en dot.notation minuscules : `agent.status.changed`, `run.completed`
- Payloads en camelCase (aligné avec la règle JSON)

**Fichiers & composants Next.js :**
- Composants React : PascalCase (`DiagramNode.tsx`, `BubbleNotification.tsx`)
- Hooks : camelCase avec préfixe `use` (`useSSEListener.ts`, `useWorkflow.ts`)
- Stores Zustand : camelCase avec suffixe `Store` (`workflowStore.ts`, `runStore.ts`)
- Utilitaires : camelCase (`parseCheckpoint.ts`, `sanitizeEnv.ts`)

**PHP / Laravel :**
- Classes : PascalCase (`RunService`, `ClaudeDriver`, `AgentStatusChanged`)
- Méthodes & variables : camelCase (`$runId`, `spawnProcess()`)
- Fichiers de config et YAML workflow : kebab-case (`feature-dev.yaml`)
- Agent IDs dans les YAML : kebab-case (`laravel-dev`, `qa`, `pm`)

### Structure Patterns

**Next.js — Organisation par type :**

```
src/
  app/               ← routes Next.js (page.tsx, layout.tsx)
  components/        ← composants React (PascalCase.tsx)
  stores/            ← stores Zustand (*Store.ts)
  hooks/             ← hooks custom (use*.ts)
  types/             ← interfaces TypeScript (*.types.ts)
  lib/               ← utilitaires purs (pas de React)
```

**Laravel — Séparation des responsabilités :**

```
app/
  Http/
    Controllers/     ← orchestration uniquement, délègue aux Services
    Resources/       ← RunResource, AgentResource (transformation camelCase ici)
  Services/          ← logique métier (RunService, CheckpointService, YamlService)
  Drivers/           ← DriverInterface + implémentations (ClaudeDriver, GeminiDriver)
  Events/            ← événements Laravel (AgentStatusChanged, RunCompleted)
  Listeners/         ← handlers SSE (SseEmitter)
```

Règle : **les Controllers ne contiennent aucune logique métier** — uniquement appels de Services et retour de Resources.

**Tests :**
- Next.js : co-located (`Component.test.tsx` à côté de `Component.tsx`)
- Laravel : `tests/Unit/` et `tests/Feature/` (convention standard)

### Format Patterns

**Réponses API — Sans wrapper :**

```php
// Dans AppServiceProvider
JsonResource::withoutWrapping();
```

Toutes les Resources retournent directement l'objet, sans clé `data`.

**Format d'erreur uniforme :**

```json
{ "message": "Texte lisible par l'utilisateur", "code": "YAML_INVALID" }
```

- Validation YAML : HTTP 422 + format ci-dessus
- Erreur CLI : HTTP 500 + format ci-dessus (sans stack trace exposée)
- Toujours un `message` humainement lisible

**Dates & timestamps :** Format ISO 8601 partout : `"2026-04-02T14:30:00Z"` — jamais de timestamps Unix.

**Booléens :** `true`/`false` natif JSON — jamais `1`/`0`.

### Communication Patterns

**SSE — Pattern d'émission (Laravel) :**

```php
// Toujours via l'EventSystem, jamais directement dans un Controller
event(new AgentStatusChanged($runId, $agentId, 'working', $step, $message));
// Le Listener SseEmitter gère l'écriture dans le stream
```

**SSE — Pattern de consommation (Next.js) :**

```typescript
// Toujours via le hook useSSEListener — jamais d'EventSource instancié directement
const { status } = useSSEListener(runId);
// Le hook met à jour les Zustand stores directement
```

**Zustand — Mises à jour immutables :**

```typescript
// ✅ Correct
agentStatusStore.setState(state => ({
  agents: { ...state.agents, [agentId]: { ...state.agents[agentId], status } }
}))
// ❌ Interdit
state.agents[agentId].status = 'working'
```

**Zustand — Pas de logique dans les stores :** Les stores ne contiennent que l'état et les setters. Toute logique de transformation est dans les hooks ou `lib/`.

### Process Patterns

**Gestion d'erreurs — Laravel :**
- Toute exception dans un Service est catchée et retransformée en `run.error` SSE
- Les erreurs de processus CLI ne propagent jamais de stack PHP dans la réponse HTTP
- NFR7 : si le JSON d'un agent est invalide, l'erreur est capturée comme `run.error` avec `message: "Invalid JSON output from {agentId}"`

**Gestion d'erreurs — Next.js :**
- Les erreurs SSE de type `run.error` mettent à jour `agentStatusStore` (état `error`) ET `runStore` (run stoppé)
- Affichage dans le diagramme (nœud en erreur) + bulle d'alerte — jamais de `console.error` seul

**Loading states — Nommage uniforme :**

```typescript
isLoading: boolean    // chargement initial
isSubmitting: boolean // action en cours (lancement d'un run)
// Jamais : loading, pending, isFetching
```

**Driver layer — Règle d'isolation :**

```php
interface DriverInterface {
    public function execute(string $prompt, array $options): string;
    public function kill(int $pid): void;
}
// RunService reçoit DriverInterface via injection — jamais ClaudeDriver directement
```

### Enforcement Guidelines

**Tout agent IA DOIT :**
- Transformer en camelCase **uniquement dans les Laravel Resources**, pas ailleurs
- Retourner les erreurs API au format `{ "message": "...", "code": "..." }`
- Utiliser `useSSEListener()` pour consommer le SSE — jamais d'`EventSource` direct
- Injecter `DriverInterface`, jamais les implémentations concrètes
- Écrire les checkpoints **avant** de marquer une étape comme complétée (NFR6)
- Sanitiser les variables d'environnement avant tout appel `Storage::put()` (NFR12)

**Anti-patterns à éviter :**
- Logique métier dans un Controller Laravel
- `EventSource` instancié directement dans un composant React
- Mutation directe de l'état Zustand
- Timestamp Unix dans un payload API ou SSE
- `console.error` seul pour les erreurs SSE (doit aussi mettre à jour le store)

## Project Structure & Boundaries

### Complete Project Directory Structure

```
XuMaestro/                          ← racine du monorepo
├── .gitignore
├── README.md
├── workflows/                        ← YAML workflow files (source de vérité, versionnés)
│   └── example-feature-dev.yaml
├── prompts/                          ← system prompt files par agent (référencés via system_prompt_file)
│   └── example-pm.md
├── runs/                             ← artefacts générés (gitignore ou versionné selon choix)
│   └── YYYY-MM-DD-HHMM/
│       ├── session.md
│       ├── checkpoint.json
│       └── agents/
│           └── {agent-id}.md
│
├── frontend/                         ← Next.js 16.2.2
│   ├── package.json
│   ├── next.config.ts                ← proxy /api/* → localhost:8000
│   ├── tailwind.config.ts
│   ├── tsconfig.json
│   ├── .env.local
│   ├── .env.example
│   └── src/
│       ├── app/
│       │   ├── layout.tsx
│       │   ├── page.tsx              ← page principale (selector + diagram + launcher)
│       │   └── globals.css
│       ├── components/
│       │   ├── WorkflowSelector.tsx  ← FR1, FR2, FR3, FR5
│       │   ├── AgentDiagram.tsx      ← FR5, FR18, FR19 (React Flow wrapper)
│       │   ├── DiagramNode.tsx       ← FR18 (nœud avec états idle/working/error/done)
│       │   ├── DiagramEdge.tsx       ← FR19 (arête animée au handoff)
│       │   ├── BubbleNotification.tsx ← FR20 (bulles SSE par agent)
│       │   ├── RunSidebar.tsx        ← FR21 (session.md append-only temps réel)
│       │   ├── ErrorAlert.tsx        ← FR22 (alerte localisée sur nœud)
│       │   ├── RunCompletionModal.tsx ← FR23 (récapitulatif fin de run)
│       │   ├── RunLauncher.tsx       ← FR7 (textarea + bouton Lancer)
│       │   └── RunHistory.tsx        ← FR31 (liste runs passés)
│       ├── stores/
│       │   ├── workflowStore.ts      ← FR1–FR6 (YAML actif, liste, config agents)
│       │   ├── runStore.ts           ← FR7–FR13 (run courant, status, durée)
│       │   └── agentStatusStore.ts   ← FR18–FR23 (états par agent, steps, bulles)
│       ├── hooks/
│       │   ├── useSSEListener.ts     ← NFR5 (EventSource + reconnexion auto)
│       │   ├── useWorkflows.ts       ← FR1, FR3 (fetch + reload)
│       │   └── useRunHistory.ts      ← FR31
│       ├── types/
│       │   ├── workflow.types.ts     ← types YAML schema (WorkflowConfig, AgentConfig)
│       │   ├── run.types.ts          ← Run, AgentStatus, Step, Checkpoint
│       │   └── sse.types.ts          ← SSE event types (AgentStatusChanged, etc.)
│       └── lib/
│           ├── apiClient.ts          ← fetch wrapper (base URL, headers)
│           └── sseEventParser.ts     ← parse et type-check les payloads SSE
│
└── backend/                          ← Laravel 13.1.1
    ├── composer.json
    ├── .env
    ├── .env.example
    ├── app/
    │   ├── Http/
    │   │   ├── Controllers/
    │   │   │   ├── RunController.php        ← POST/GET/DELETE /api/runs, retry-step
    │   │   │   ├── WorkflowController.php   ← GET /api/workflows
    │   │   │   └── SseController.php        ← GET /api/runs/{id}/stream
    │   │   └── Resources/
    │   │       ├── RunResource.php          ← camelCase transformation ici
    │   │       ├── AgentResource.php
    │   │       └── WorkflowResource.php
    │   ├── Services/
    │   │   ├── RunService.php               ← orchestration pipeline séquentiel (FR8–FR12)
    │   │   ├── YamlService.php              ← chargement + validation schema YAML (FR4, FR6)
    │   │   ├── CheckpointService.php        ← write/read checkpoint.json (FR24–FR25, NFR6)
    │   │   ├── ArtifactService.php          ← dossiers run, session.md, outputs (FR28–FR30)
    │   │   └── SseStreamService.php         ← gestion du stream SSE ouvert (FR18–FR22)
    │   ├── Drivers/
    │   │   ├── DriverInterface.php          ← execute(), kill() (NFR8, NFR10)
    │   │   ├── ClaudeDriver.php             ← spawn claude -p avec flags (NFR8)
    │   │   └── GeminiDriver.php             ← spawn gemini -p avec flags (NFR8)
    │   ├── Events/
    │   │   ├── AgentStatusChanged.php
    │   │   ├── AgentBubble.php
    │   │   ├── RunCompleted.php
    │   │   └── RunError.php
    │   ├── Listeners/
    │   │   └── SseEmitter.php               ← écrit les events SSE dans le stream ouvert
    │   └── Exceptions/
    │       ├── YamlValidationException.php
    │       ├── CliExecutionException.php
    │       └── InvalidJsonOutputException.php ← NFR7
    ├── config/
    │   └── xu-maestro.php                  ← timeout par défaut, paths workflows/runs
    ├── routes/
    │   └── api.php
    └── tests/
        ├── Unit/
        │   ├── Services/
        │   │   ├── RunServiceTest.php
        │   │   ├── YamlServiceTest.php
        │   │   └── CheckpointServiceTest.php
        │   └── Drivers/
        │       ├── ClaudeDriverTest.php
        │       └── GeminiDriverTest.php
        └── Feature/
            ├── RunApiTest.php
            ├── WorkflowApiTest.php
            └── SseStreamTest.php
```

### Architectural Boundaries

**API Boundary (Laravel ↔ Next.js) :**
- Toutes les requêtes Next.js → `localhost:8000/api/*` via proxy next.config.ts
- Laravel expose uniquement des routes sous `/api/`
- SSE stream sur `/api/runs/{id}/stream` — connexion longue durée

**Driver Boundary :**
- `RunService` ne connaît que `DriverInterface` — jamais les implémentations concrètes
- Le binding est dans `AppServiceProvider` : résolution selon le champ `engine` du YAML

**Process Boundary :**
- Les processus CLI s'exécutent exclusivement depuis `project_path` (NFR11)
- `ArtifactService` est le seul point d'accès en écriture au filesystem runs/

**State Boundary :**
- Les Zustand stores sont mis à jour **uniquement** via `useSSEListener` pendant un run actif
- `workflowStore` est mis à jour via `useWorkflows` (GET /api/workflows)
- Aucun store ne fait de requête réseau directe — tout passe par les hooks

### Requirements to Structure Mapping

| FR Category | Backend | Frontend |
|---|---|---|
| Gestion Workflows (FR1–FR6) | `YamlService`, `WorkflowController`, `WorkflowResource` | `WorkflowSelector`, `workflowStore`, `useWorkflows` |
| Exécution & Orchestration (FR7–FR13) | `RunService`, `RunController`, `Drivers/` | `RunLauncher`, `runStore` |
| Communication inter-agents (FR14–FR17) | `ArtifactService` (session.md), `CheckpointService` | `useSSEListener`, `sseEventParser` |
| Monitoring temps réel (FR18–FR23) | `SseStreamService`, `Events/`, `SseEmitter` | `AgentDiagram`, `DiagramNode`, `BubbleNotification`, `RunSidebar`, `ErrorAlert`, `RunCompletionModal` |
| Résilience (FR24–FR27) | `CheckpointService`, retry dans `RunService` | `ErrorAlert` (bouton retry), `agentStatusStore` |
| Artefacts & Traçabilité (FR28–FR31) | `ArtifactService` | `RunHistory`, `RunSidebar` |

### Data Flow

```
[User] brief + workflow sélectionné
  → POST /api/runs (RunController → RunService)
  → RunService : valide YAML (YamlService), crée dossier run (ArtifactService)
  → RunService : spawn agent 1 via DriverInterface
  → Process stdout → parse JSON → event(AgentStatusChanged)
  → SseEmitter → SSE stream → useSSEListener (Next.js)
  → agentStatusStore.setState → React Flow re-render
  → ArtifactService : append session.md, write checkpoint.json
  → [agent suivant ou run.completed/run.error]
```

### Development Workflow Integration

```bash
# Terminal 1 — Backend
cd backend && php artisan serve          # port 8000

# Terminal 2 — Frontend
cd frontend && npm run dev               # port 3000

# Accès
open http://localhost:3000
```

Pas de Docker, pas de CI/CD pour le MVP — deux commandes suffisent.

## Architecture Validation Results

### Coherence Validation ✅

**Decision Compatibility :** Toutes les versions et technologies sont compatibles.
Next.js 16.2.2 + React Flow + Zustand + EventSource — stack cohérente sans conflits.
Laravel 13.1.1 + Process Facade + Event/Listener — pattern éprouvé pour la gestion de processus.

**Pattern Consistency :** camelCase uniquement dans les Resources (règle sans ambiguïté), Zustand immuable, useSSEListener exclusif — zéro contradiction interne.

**Structure Alignment :** Chaque composant de la structure supporte les décisions architecturales. Les boundaries (Driver, API, Process, State) sont respectés par la structure physique.

### Requirements Coverage Validation ✅

**Functional Requirements : 31/31 couvertes**
Toutes les FR sont mappées à des composants physiques précis (voir tableau Requirements to Structure).

**Non-Functional Requirements : 12/12 couvertes**

| NFR | Mécanisme | Statut |
|---|---|---|
| NFR1 (latence SSE < 200ms) | Long-running SSE request direct | ✅ |
| NFR2 (spawn CLI < 5s) | Process Facade natif Laravel | ✅ |
| NFR3 (diagram fluide ≤5 nœuds) | React Flow déclaratif | ✅ |
| NFR4 (zéro zombie process) | `DriverInterface::kill()` + finally block | ✅ |
| NFR5 (reconnexion SSE auto) | `useSSEListener` + EventSource retry | ✅ |
| NFR6 (checkpoint avant completed) | `CheckpointService::write()` avant marquage | ✅ |
| NFR7 (JSON invalide capturé) | `InvalidJsonOutputException` dans RunService | ✅ |
| NFR8 (drivers interchangeables) | `DriverInterface` + binding DI par engine | ✅ |
| NFR9 (validation contrat JSON) | Dans `RunService` après `execute()`, avant propagation | ✅ |
| NFR10 (driver layer isolé) | `DriverInterface` injectée, jamais concrète | ✅ |
| NFR11 (CLI depuis project_path) | `ClaudeDriver`/`GeminiDriver` : `cwd: $projectPath` | ✅ |
| NFR12 (no credentials loggés) | `ArtifactService` sanitise avant `Storage::put()` | ✅ |

### Gap Analysis Results

**Gap critique résolu — Mécanisme SSE/RunService**

Choix : **long-running SSE request** (Option A).

`GET /api/runs/{id}/stream` est une requête PHP longue durée. `SseController` invoque `RunService` directement dans ce process. Les événements sont streamés via `response()->stream()`.

Implication : `SseStreamService` est supprimé comme service séparé. `SseController` orchestre `RunService` et streame les événements directement. Cycle de vie du run = cycle de vie de la connexion SSE (acceptable pour usage local). Annulation (FR13) : déconnexion client → PHP `connection_aborted()` → process killed proprement.

**Gap important résolu — Validation JSON (NFR9)**

Validation du contrat `{ step, status, output, next_action, errors }` dans `RunService` après chaque appel `DriverInterface::execute()`, avant propagation à l'agent suivant. Échec → `InvalidJsonOutputException` → `run.error` SSE émis.

**Gap mineur résolu — `config/xu-maestro.php`**

```php
return [
    'default_timeout' => 120,
    'workflows_path'  => base_path('../../workflows'),
    'runs_path'       => base_path('../../runs'),
    'prompts_path'    => base_path('../../prompts'),
];
```

### Architecture Completeness Checklist

**✅ Requirements Analysis**
- [x] Contexte projet analysé (31 FR, 12 NFR, 5 préoccupations transversales)
- [x] Complexité évaluée (Moyenne, full-stack local, single-user)
- [x] Contraintes techniques identifiées (headless CLI flags, rate limits, SSE)
- [x] Préoccupations transversales mappées

**✅ Architectural Decisions**
- [x] Stack complète avec versions (Next.js 16.2.2, Laravel 13.1.1)
- [x] API REST resource-based documentée (7 endpoints)
- [x] 4 types d'événements SSE spécifiés avec payloads
- [x] Structure checkpoints définie (checkpoint.json schema)
- [x] Driver layer isolé (DriverInterface)

**✅ Implementation Patterns**
- [x] Nommage JSON : camelCase dans Resources uniquement
- [x] Structure Next.js : par type (components, stores, hooks, types, lib)
- [x] Structure Laravel : Controllers → Services → Drivers
- [x] Anti-patterns documentés (5 règles d'exclusion)
- [x] Enforcement guidelines pour agents IA

**✅ Project Structure**
- [x] Arbre complet défini (frontend + backend + workflows + runs + prompts)
- [x] Chaque FR mappée à un fichier/dossier précis
- [x] Data flow documenté bout-en-bout
- [x] Dev setup : 2 commandes (`php artisan serve` + `npm run dev`)

### Architecture Readiness Assessment

**Statut global : PRÊT POUR L'IMPLÉMENTATION**

**Niveau de confiance : Élevé**

**Points forts :**
- Architecture filesystem-only — zéro dépendance externe (pas de Redis, pas de DB)
- Driver layer isolé dès le départ — résiliente aux changements d'interface CLI
- Patterns anti-conflits complets — 5 catégories de règles pour les agents IA
- Long-running SSE request — pattern optimal pour localhost single-user
- Séquence d'implémentation claire et ordonnée (8 étapes)

**Axes d'évolution future (post-MVP) :**
- Migration SSE → queue worker + Redis si besoin de runs en arrière-plan (Phase 2)
- Swap du diagramme React Flow → PixiJS canvas pour le pixel art (Phase 3)
- Cross-agent memory sharing → `memory/` folder pattern (Phase 2)

### Implementation Handoff

**Première story d'implémentation :**

```bash
npx create-next-app@latest frontend --typescript --tailwind --eslint --no-git
laravel new backend --no-interaction
```

**Tout agent IA implémentant ce projet DOIT :**
1. Consulter ce document avant toute décision architecturale
2. Respecter les patterns de nommage (camelCase dans Resources uniquement)
3. Ne jamais instancier `ClaudeDriver`/`GeminiDriver` directement — toujours `DriverInterface`
4. Écrire le checkpoint **avant** de marquer une étape complétée
5. Router toutes les erreurs SSE via `run.error` — jamais de crash silencieux
