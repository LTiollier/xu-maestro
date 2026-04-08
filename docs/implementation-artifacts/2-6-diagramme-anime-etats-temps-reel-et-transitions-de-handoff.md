# Story 2.6 : Diagramme animé — états temps réel et transitions de handoff

Status: done

## Story

As a développeur,
I want que le diagramme se mette à jour en temps réel avec les états des agents et anime les handoffs,
So that je vois visuellement qui travaille, qui a fini, et quand le contexte passe d'un agent au suivant.

## Acceptance Criteria

1. **Given** un run actif avec un événement `agent.status.changed { status: "working" }` reçu — **When** `agentStatusStore` est mis à jour — **Then** le custom node `agentCard` de l'agent concerné se re-rend avec `data.status: "working"` (border blue-500, box-shadow) sans re-monter `<ReactFlow>`
2. **Given** un agent passe à `done` — **Then** son custom node affiche l'état `done` (border emerald-500, opacité 70%)
3. **And** le custom edge `pipelineConnector` entre cet agent et le suivant reçoit `data.status: "done"` — sa couleur passe à emerald-500 avec la transition CSS 300ms déjà en place dans `DiagramEdge.tsx`
4. **And** le custom node de l'agent suivant reçoit `data.status: "working"` — les mises à jour du node courant et du node suivant se produisent dans le même render React (même tick)
5. **Given** tous les agents sont `done` — **Then** le diagramme reste dans son état final lisible, aucun spinner global ne subsiste
6. **And** `<ReactFlow>` n'est jamais re-monté pendant un run — seuls les champs `data` des nodes et edges sont mis à jour

## Tasks / Subtasks

- [x] **T1 — Connecter `agentStatusStore` à `AgentDiagramInner`** (AC 1, 2, 3, 4, 6)
  - [x] Importer `useAgentStatusStore` dans `AgentDiagram.tsx`
  - [x] Subsccrire au champ `agents` dans `AgentDiagramInner`
  - [x] Ajouter `agents` dans les deps du `useMemo` existant
  - [x] Remplacer `status: 'idle' as const` par `status: agents[agent.id]?.status ?? 'idle'` pour chaque node
  - [x] Dériver le statut des edges : `agents[agent.id]?.status === 'done' ? 'done' : 'inactive'`

- [x] **T2 — Vérification manuelle** (AC 1-6)
  - [x] `npx tsc --noEmit` : 0 erreur
  - [x] `eslint src/` : 0 erreur, 0 warning

### Review Findings (2026-04-08)

- [x] [Review][Patch] Statuts agents périmés au changement de workflow — `resetAgents()` jamais appelé sur `setSelectedWorkflow` ; un agent ID partagé entre deux workflows hérite du statut du run précédent [AgentDiagram.tsx]
- [x] [Review][Defer] `agents` subscription déclenche `useMemo` sur chaque `setAgentBubble` (perf mineure, ≤5 agents MVP) [AgentDiagram.tsx] — deferred
- [x] [Review][Defer] Edge affiche `inactive` quand l'agent source est en `error` — état error des edges non spécifié en 2.6 [AgentDiagram.tsx] — deferred
- [x] [Review][Defer] Garantie AC4 "même tick" non applicable avec deux events SSE distincts — contrainte architecturale backend [AgentDiagram.tsx] — deferred
- [x] [Review][Defer] `slice(0, -1)` sur workflow mono-agent produit zéro edges — comportement pré-existant (1.5) [AgentDiagram.tsx] — deferred, pre-existing

## Dev Notes

### §SEUL fichier à modifier

```
frontend/src/components/AgentDiagram.tsx   ← MODIFIER uniquement
```

**Aucun autre fichier à toucher.** Tous les composants (`AgentCard`, `DiagramEdge`), stores (`agentStatusStore`) et types (`AgentStatus`) sont déjà en place depuis les stories 1.5 et 2.5.

### §Changement exact dans `AgentDiagramInner`

État actuel (story 1.5) — nodes figés à `idle` :

```typescript
function AgentDiagramInner() {
  const { selectedWorkflow } = useWorkflowStore()

  const { nodes, edges } = useMemo(() => {
    // ...
    const nodes = selectedWorkflow.agents.map((agent, index) => ({
      // ...
      data: {
        name: agent.id,
        engine: agent.engine,
        steps: agent.steps,
        status: 'idle' as const,           // ← HARDCODÉ, ne réagit pas au SSE
      },
    }))

    const edges = selectedWorkflow.agents.slice(0, -1).map((agent, index) => ({
      // ...
      data: { status: 'inactive' },        // ← HARDCODÉ
    }))
  }, [selectedWorkflow])
```

État cible (story 2.6) — nodes réactifs à `agentStatusStore` :

```typescript
import { useAgentStatusStore } from '@/stores/agentStatusStore'

function AgentDiagramInner() {
  const { selectedWorkflow } = useWorkflowStore()
  const { agents } = useAgentStatusStore()          // ← AJOUT

  const { nodes, edges } = useMemo(() => {
    if (!selectedWorkflow) return { nodes: [], edges: [] }

    const nodes = selectedWorkflow.agents.map((agent, index) => ({
      id: agent.id,
      type: 'agentCard' as const,
      position: { x: 0, y: index * 220 },
      data: {
        name: agent.id,
        engine: agent.engine,
        steps: agent.steps,
        status: agents[agent.id]?.status ?? 'idle',  // ← RÉACTIF
      },
    }))

    const edges = selectedWorkflow.agents.slice(0, -1).map((agent, index) => ({
      id: `${agent.id}-to-${selectedWorkflow.agents[index + 1].id}`,
      source: agent.id,
      target: selectedWorkflow.agents[index + 1].id,
      type: 'pipelineConnector' as const,
      data: {
        status: agents[agent.id]?.status === 'done' ? 'done' : 'inactive',  // ← RÉACTIF
      },
    }))

    return { nodes, edges }
  }, [selectedWorkflow, agents])                    // ← agents dans les deps
  // ...reste inchangé
```

### §Pourquoi `useMemo` et non `useReactFlow().setNodes()`

L'épique mentionne `setNodes()` comme mécanisme. **Ne pas utiliser `setNodes()`** dans ce contexte : `AgentDiagram` passe `nodes` et `edges` comme **props contrôlées** à `<ReactFlow>`. En mode contrôlé, `setNodes()` entre en conflit avec les props externes. La bonne approche est de dériver les nodes depuis les deps `useMemo` — React Flow re-rend les nodes existants (même `id`) sans les re-monter. Les updates via `useMemo` se produisent dans le **même render** (Zustand utilise le batching React 18), satisfaisant l'AC "même tick".

### §Transitions CSS déjà en place — rien à ajouter

`DiagramEdge.tsx` a déjà `style={{ stroke: color, strokeWidth: 2, transition: 'stroke 300ms' }}` — la transition 300ms est opérationnelle dès qu'on change `data.status`.

`AgentCard.tsx` a déjà tous les styles par status :

```typescript
const wrapperStyles = {
  idle:    'opacity-45',
  working: 'shadow-[0_0_12px_rgba(59,130,246,0.4)]',
  done:    'opacity-70',
  error:   'animate-pulse',
}
const cardRingStyles = {
  idle:    'ring-zinc-700',
  working: 'ring-blue-500',
  done:    'ring-emerald-500',
  error:   'ring-red-500',
}
```

Aucune modification de `AgentCard.tsx` ni `DiagramEdge.tsx`.

### §Logique edge — index vs agentId

L'edge entre `agents[i]` et `agents[i+1]` change à `done` **quand `agents[i]` passe à `done`**. Le mapping est :

```
edge[i] source = selectedWorkflow.agents[i].id
edge[i] status = agents[selectedWorkflow.agents[i].id]?.status === 'done' ? 'done' : 'inactive'
```

**Pas d'état `active` (blue-400) en 2.6** — le `DiagramEdge` supporte les 3 états mais l'AC 2.6 ne prescrit que `inactive` → `done`. L'état `active` est réservé pour une future animation handoff (non scopée ici).

### §Invariant critique — React Flow ne se re-monte pas

`<ReactFlow>` est rendu dans `<ReactFlowProvider>` en `AgentDiagram`. Tant que le composant `AgentDiagram` lui-même n'est pas unmounté (ce qui n'arrive pas — il est rendu dans `page.tsx` au niveau supérieur), `ReactFlow` ne se re-monte pas. Les nodes avec les mêmes `id` sont mis à jour sur place — c'est la garantie React Flow pour le mode contrôlé.

**Anti-pattern à éviter :** ne pas remonter `<ReactFlow>` en rendant conditionnellement `AgentDiagram` basé sur le run actif. Le diagramme doit être persistant.

### §Scope délimité — Ce qui N'appartient PAS à cette story

- **`BubbleBox` sous la `AgentCard`** — Story 2.7b. `AgentCard.tsx` n'a pas de prop `bubble` en 2.6 et c'est intentionnel. Le champ `bubbleMessage` dans `agentStatusStore` est ignoré ici.
- **`useSSEListener` appelé depuis un vrai composant** — Story 2.7a (`LaunchBar`). En 2.6, le store peut être mis à jour manuellement pour tester.
- **`StepItem` avec états `working`/`done` dynamiques** — Les steps restent `"pending"` en 2.6. La granularité step-level est Epic 3.
- **Espacement Y dynamique selon hauteur de card** — Reporté depuis 1.5, toujours deferred. `index * 220` reste en place.

### §Guardrails — Erreurs critiques à éviter

| ❌ Interdit | ✅ Correct |
|---|---|
| `useReactFlow().setNodes()` en mode contrôlé | Dériver `nodes` via `useMemo([selectedWorkflow, agents])` |
| Oublier `agents` dans les deps `useMemo` | `}, [selectedWorkflow, agents])` — les deux sont requis |
| `status: agents[agent.id].status` (sans `?.`) | `status: agents[agent.id]?.status ?? 'idle'` (agent peut ne pas être dans le store) |
| Modifier `AgentCard.tsx` ou `DiagramEdge.tsx` | Aucun changement — les styles sont déjà complets |
| Mettre `useAgentStatusStore` hors de `AgentDiagramInner` | Doit être dans `AgentDiagramInner` (inside `ReactFlowProvider`) |

### §Pattern Zustand — subscription sélective

```typescript
// ✅ Correct — ne re-rend que quand agents change
const { agents } = useAgentStatusStore()

// ⚠️ Alternative valide mais subscribe à tout le store
const agents = useAgentStatusStore(state => state.agents)
```

Les deux fonctionnent. La première est cohérente avec le pattern utilisé dans `workflowStore` existant.

### §AGENTS.md — Avertissement

`frontend/AGENTS.md` : **"This is NOT the Next.js you know"**. Ne pas faire d'hypothèses sur les APIs Next.js. Ce changement ne touche que React Flow / Zustand — hors scope de l'avertissement Next.js.

### Project Structure Notes

- `AgentDiagram.tsx` est dans `frontend/src/components/` — pas de déplacement
- `useAgentStatusStore` est importé depuis `@/stores/agentStatusStore` (alias `@` = `frontend/src/`)
- Pas de nouveau fichier, pas de nouvelle dépendance npm

### References

- [Source: docs/planning-artifacts/epics.md#Story-2.6] — User story, ACs complets
- [Source: docs/planning-artifacts/architecture.md#Frontend-Architecture] — React Flow dépend de `agentStatusStore`; mode contrôlé; pas de re-mount
- [Source: docs/planning-artifacts/ux-design-specification.md#AgentCard] — États visuels idle/working/done/error
- [Source: docs/planning-artifacts/ux-design-specification.md#PipelineConnector] — Transition 300ms, inactive/active/done
- [Source: frontend/src/components/AgentDiagram.tsx] — Code actuel à modifier (useMemo, ReactFlow controlled)
- [Source: frontend/src/components/AgentCard.tsx] — Styles par status déjà implémentés
- [Source: frontend/src/components/DiagramEdge.tsx] — Transition CSS déjà présente (`transition: 'stroke 300ms'`)
- [Source: frontend/src/stores/agentStatusStore.ts] — `useAgentStatusStore`, champ `agents`
- [Source: docs/implementation-artifacts/2-5-client-sse-et-mise-a-jour-des-stores-zustand.md#Dev-Notes] — Stores, patterns Zustand, portée 2.6 délimitée
- [Source: docs/implementation-artifacts/deferred-work.md#1-5] — Espacement Y et containerHeight toujours deferred en 2.6

## Dev Agent Record

### Agent Model Used

claude-sonnet-4-6

### Debug Log References

Aucun — implémentation directe, 0 erreur TypeScript, 0 erreur ESLint.

### Completion Notes List

- `AgentDiagram.tsx` : ajout de `useAgentStatusStore` import + subscription au champ `agents` dans `AgentDiagramInner`
- `useMemo` mis à jour avec `agents` dans les deps — recompute automatique à chaque changement de statut SSE
- Nodes : `data.status` dérivé de `agents[agent.id]?.status ?? 'idle'` — réactif sans cast TypeScript (mêmes unions)
- Edges : `data.status` dérivé de `agents[agent.id]?.status === 'done' ? 'done' : 'inactive'` — transition 300ms opérationnelle via CSS existant dans `DiagramEdge.tsx`
- React Flow reste en mode contrôlé (props `nodes`/`edges`) — aucun remount pendant un run
- `AgentCard.tsx`, `DiagramEdge.tsx`, `agentStatusStore.ts` : aucune modification

### File List

- frontend/src/components/AgentDiagram.tsx (modifié)
