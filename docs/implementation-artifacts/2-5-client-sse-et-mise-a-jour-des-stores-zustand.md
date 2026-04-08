# Story 2.5 : Client SSE et mise à jour des stores Zustand

Status: done
Epic: 2
Story: 5
Date: 2026-04-07

## Story

As a développeur,
I want que le hook `useSSEListener` consomme les événements SSE et mette à jour les stores Zustand,
So that le diagramme et les bulles réagissent en temps réel sans action utilisateur.

## Acceptance Criteria

1. **Given** un run actif avec un stream SSE ouvert — **When** un événement `agent.status.changed` est reçu — **Then** `agentStatusStore` est mis à jour immédiatement via spread immutable avec le nouvel état de l'agent
2. **Given** la connexion SSE est perdue pendant un run actif — **Then** `useSSEListener` tente une reconnexion automatique via le retry natif de `EventSource` (NFR5)
3. **And** `useSSEListener` est le seul point de consommation SSE — aucun `EventSource` instancié directement dans un composant
4. **And** les mises à jour Zustand se font uniquement via spread immutable (`{ ...state, ... }`)
5. **And** aucune logique de transformation n'est dans les stores — uniquement dans les hooks ou `lib/`

## Tasks / Subtasks

- [x] **T1 — Créer `sse.types.ts`** (AC 1, 3)
  - [x] Définir les 4 interfaces event SSE : `AgentStatusChangedEvent`, `AgentBubbleEvent`, `RunCompletedEvent`, `RunErrorEvent`
  - [x] Définir le type union `SseEvent` et la constante `SSE_EVENT_TYPES`
  - [x] Toutes les propriétés en camelCase strict (jamais snake_case)

- [x] **T2 — Créer `run.types.ts`** (AC 1)
  - [x] Interfaces `AgentState`, `RunState` pour les stores
  - [x] Type union `AgentStatus = 'idle' | 'working' | 'done' | 'error'`
  - [x] Type union `RunStatus = 'idle' | 'running' | 'completed' | 'error'`

- [x] **T3 — Créer `lib/sseEventParser.ts`** (AC 1, 3, 5)
  - [x] Fonctions `parseAgentStatusChanged`, `parseAgentBubble`, `parseRunCompleted`, `parseRunError` — retournent `null` si payload invalide
  - [x] Parse `JSON.parse(rawData)` — retourne `null` si `SyntaxError` ou payload malformé
  - [x] Valide la présence des champs obligatoires par type d'event (guard naïf, pas de lib externe)
  - [x] AUCUNE logique de transformation — juste parse + validation structurelle

- [x] **T4 — Créer `stores/agentStatusStore.ts`** (AC 1, 4, 5)
  - [x] État : `agents: Record<string, AgentState>` (initialement `{}`)
  - [x] Setters : `setAgentStatus(agentId, status, step, message)`, `setAgentBubble(agentId, message)`, `resetAgents()`
  - [x] Toutes les mises à jour via spread immutable — aucune mutation directe
  - [x] Aucune logique métier dans le store — setters purs

- [x] **T5 — Créer `stores/runStore.ts`** (AC 1, 4, 5)
  - [x] État : `runId: string | null`, `status: RunStatus`, `duration: number | null`, `runFolder: string | null`, `errorMessage: string | null`
  - [x] Setters : `setRunId(runId)`, `setRunCompleted(duration, runFolder)`, `setRunError(message)`, `resetRun()`
  - [x] Aucune logique dans le store — setters purs

- [x] **T6 — Créer `hooks/useSSEListener.ts`** (AC 1, 2, 3, 4)
  - [x] Signature : `useSSEListener(runId: string | null): { connectionStatus: 'idle' | 'connected' | 'error' }`
  - [x] Ouvre `EventSource` sur `/api/runs/${runId}/stream` uniquement si `runId !== null`
  - [x] `addEventListener` pour les 4 types d'events SSE nommés (jamais `onmessage` pour les named events)
  - [x] Sur `agent.status.changed` → `agentStatusStore` update via `useAgentStatusStore.getState().setAgentStatus()`
  - [x] Sur `agent.bubble` → `agentStatusStore` update via `setAgentBubble()`
  - [x] Sur `run.completed` → `runStore` update via `setRunCompleted()` + fermer `EventSource`
  - [x] Sur `run.error` → `agentStatusStore` `setAgentStatus(agentId, 'error', step, message)` + `runStore` `setRunError()` + fermer `EventSource`
  - [x] Cleanup `useEffect` : `eventSource.close()` à chaque changement de `runId` ou unmount
  - [x] Reconnexion automatique : **pas de code explicite** — `EventSource` native reconnecte automatiquement (NFR5)
  - [x] `'use client'` en tête de fichier (hook avec side-effects)
  - [x] Fix ESLint `react-hooks/set-state-in-effect` : `setConnectionStatus('connected')` déplacé dans `es.onopen` callback

- [x] **T7 — Vérification manuelle**
  - [x] Pas de tests frontend existants — vérification via TypeScript (0 erreur) + ESLint (0 erreur)
  - [x] `npx tsc --noEmit` : ✅ compilation propre
  - [x] `eslint src/` : ✅ 0 erreur, 0 warning

### Review Findings (2026-04-08)

- [x] [Review][Patch] `AgentStatus` dupliqué dans `sse.types.ts` ET `run.types.ts` — source unique dans `sse.types.ts`, ré-export via `import type` + `export type` dans `run.types.ts`. [sse.types.ts:1, run.types.ts:1]
- [x] [Review][Patch] `parseAgentStatusChanged` : `data.status` non validé contre les valeurs autorisées — ajout de `VALID_AGENT_STATUSES` + guard `includes()`. [sseEventParser.ts:9]
- [x] [Review][Patch] Handler `RUN_ERROR` appelle `setRunError()` même si `parseRunError()` retourne `null` — ajout `if (!payload) return` en tête du handler. [useSSEListener.ts:60]
- [x] [Review][Patch] `setRunCompleted` ne réinitialise pas `errorMessage` — `errorMessage: null` ajouté dans `setRunCompleted`. [runStore.ts:23]
- [x] [Review][Patch] `esRef` assigné mais jamais lu — `useRef` et `esRef` supprimés. [useSSEListener.ts:19, 25]
- [x] [Review][Defer] `bubbleMessage` non vidé lors d'une transition de status — story 2.6/2.7b décidera du comportement visuel des bulles — deferred
- [x] [Review][Defer] `AgentBubble.step` non stocké dans le store — Epic 3 (granularité step-level) ; `step: 0` partout per design 2.4 — deferred
- [x] [Review][Defer] `checkpointPath` sur `RunErrorEvent` non consommé — Epic 3 — deferred
- [x] [Review][Defer] `RunCompletedEvent.agentCount`/`.status` non stockés — story 2.7b (`RunSummaryModal`) — deferred
- [x] [Review][Defer] `setRunId(null)` ne reset pas `errorMessage`/`duration` — par design, `resetRun()` est le chemin de reset complet — deferred
- [x] [Review][Defer] Race `runStore: 'running'` vs SSE failure — story 2.7a (`LaunchBar`) gère la corrélation `runId` + SSE — deferred
- [x] [Review][Defer] `runFolder` non validé pour chaîne vide — localhost MVP, serveur toujours correct — deferred
- [x] [Review][Defer] Timeout connexion `EventSource` absent — localhost MVP, pas de proxy réseau — deferred
- [x] [Review][Defer] Race ordering `run.completed` avant dernier `agent.status.changed` — Laravel garantit l'ordre d'émission — deferred
- [x] [Review][Defer] Multiples `RUN_ERROR`, dernier gagne — serveur émet au plus un `run.error` par run — deferred

## Dev Notes

### §Fichiers à créer — chemins exacts

```
frontend/src/
├── types/
│   ├── sse.types.ts           ← CRÉER (types SSE events)
│   └── run.types.ts           ← CRÉER (AgentState, RunState, enums statuts)
├── lib/
│   └── sseEventParser.ts      ← CRÉER (parse + validation structurelle)
├── stores/
│   ├── agentStatusStore.ts    ← CRÉER (états agents)
│   └── runStore.ts            ← CRÉER (état run courant)
└── hooks/
    └── useSSEListener.ts      ← CRÉER (hook principal SSE)
```

**Pas de modification requise dans cette story :** `AgentDiagram.tsx`, `AgentCard.tsx`, `page.tsx`, `workflowStore.ts` — la connexion `agentStatusStore` → React Flow est Story 2.6.

### §Schémas de types — `sse.types.ts`

```typescript
// frontend/src/types/sse.types.ts

export type AgentStatus = 'idle' | 'working' | 'done' | 'error'
export type RunStatus = 'idle' | 'running' | 'completed' | 'error'

export interface AgentStatusChangedEvent {
  runId: string
  agentId: string
  status: AgentStatus
  step: number
  message: string
  timestamp: string  // ISO 8601
}

export interface AgentBubbleEvent {
  runId: string
  agentId: string
  message: string
  step: number
  timestamp: string
}

export interface RunCompletedEvent {
  runId: string
  duration: number    // ms
  agentCount: number
  status: 'completed'
  runFolder: string
  timestamp: string
}

export interface RunErrorEvent {
  runId: string
  agentId: string
  step: number
  message: string
  checkpointPath: string
  timestamp: string
}

export const SSE_EVENT_TYPES = {
  AGENT_STATUS_CHANGED: 'agent.status.changed',
  AGENT_BUBBLE: 'agent.bubble',
  RUN_COMPLETED: 'run.completed',
  RUN_ERROR: 'run.error',
} as const

export type SseEventType = typeof SSE_EVENT_TYPES[keyof typeof SSE_EVENT_TYPES]
```

### §Schéma de types — `run.types.ts`

```typescript
// frontend/src/types/run.types.ts
// (re-export AgentStatus, RunStatus depuis sse.types.ts si besoin de cohérence)

export interface AgentState {
  status: 'idle' | 'working' | 'done' | 'error'
  step: number
  bubbleMessage: string    // dernier message bulle reçu
  errorMessage: string     // message d'erreur si status === 'error'
}

export interface RunState {
  runId: string | null
  status: 'idle' | 'running' | 'completed' | 'error'
  duration: number | null   // ms
  runFolder: string | null
  errorMessage: string | null
}
```

### §`sseEventParser.ts` — parse sans lib externe

```typescript
// frontend/src/lib/sseEventParser.ts
import type { AgentStatusChangedEvent, AgentBubbleEvent, RunCompletedEvent, RunErrorEvent } from '@/types/sse.types'

export function parseAgentStatusChanged(raw: string): AgentStatusChangedEvent | null {
  try {
    const data = JSON.parse(raw)
    if (!data.runId || !data.agentId || !data.status) return null
    return data as AgentStatusChangedEvent
  } catch {
    return null
  }
}

export function parseAgentBubble(raw: string): AgentBubbleEvent | null {
  try {
    const data = JSON.parse(raw)
    if (!data.runId || !data.agentId || !data.message) return null
    return data as AgentBubbleEvent
  } catch {
    return null
  }
}

export function parseRunCompleted(raw: string): RunCompletedEvent | null {
  try {
    const data = JSON.parse(raw)
    if (!data.runId || data.duration === undefined) return null
    return data as RunCompletedEvent
  } catch {
    return null
  }
}

export function parseRunError(raw: string): RunErrorEvent | null {
  try {
    const data = JSON.parse(raw)
    if (!data.runId || !data.message) return null
    return data as RunErrorEvent
  } catch {
    return null
  }
}
```

**Règle critique :** `sseEventParser.ts` ne fait QUE parser et valider — aucune logique de transformation, aucun side effect, aucun appel store.

### §`agentStatusStore.ts` — pattern exact

```typescript
// frontend/src/stores/agentStatusStore.ts
import { create } from 'zustand'
import type { AgentState } from '@/types/run.types'

interface AgentStatusStoreState {
  agents: Record<string, AgentState>
  setAgentStatus: (agentId: string, status: AgentState['status'], step: number, message: string) => void
  setAgentBubble: (agentId: string, message: string) => void
  resetAgents: () => void
}

const DEFAULT_AGENT_STATE: AgentState = {
  status: 'idle',
  step: 0,
  bubbleMessage: '',
  errorMessage: '',
}

export const useAgentStatusStore = create<AgentStatusStoreState>((set) => ({
  agents: {},
  setAgentStatus: (agentId, status, step, message) =>
    set((state) => ({
      agents: {
        ...state.agents,
        [agentId]: {
          ...(state.agents[agentId] ?? DEFAULT_AGENT_STATE),
          status,
          step,
          errorMessage: status === 'error' ? message : (state.agents[agentId]?.errorMessage ?? ''),
        },
      },
    })),
  setAgentBubble: (agentId, message) =>
    set((state) => ({
      agents: {
        ...state.agents,
        [agentId]: {
          ...(state.agents[agentId] ?? DEFAULT_AGENT_STATE),
          bubbleMessage: message,
        },
      },
    })),
  resetAgents: () => set({ agents: {} }),
}))
```

### §`runStore.ts` — pattern exact

```typescript
// frontend/src/stores/runStore.ts
import { create } from 'zustand'

interface RunStoreState {
  runId: string | null
  status: 'idle' | 'running' | 'completed' | 'error'
  duration: number | null
  runFolder: string | null
  errorMessage: string | null
  setRunId: (runId: string | null) => void
  setRunCompleted: (duration: number, runFolder: string) => void
  setRunError: (message: string) => void
  resetRun: () => void
}

export const useRunStore = create<RunStoreState>((set) => ({
  runId: null,
  status: 'idle',
  duration: null,
  runFolder: null,
  errorMessage: null,
  setRunId: (runId) => set({ runId, status: runId ? 'running' : 'idle' }),
  setRunCompleted: (duration, runFolder) => set({ status: 'completed', duration, runFolder }),
  setRunError: (message) => set({ status: 'error', errorMessage: message }),
  resetRun: () => set({ runId: null, status: 'idle', duration: null, runFolder: null, errorMessage: null }),
}))
```

### §`useSSEListener.ts` — hook complet

```typescript
'use client'

import { useEffect, useRef, useState } from 'react'
import { useAgentStatusStore } from '@/stores/agentStatusStore'
import { useRunStore } from '@/stores/runStore'
import { SSE_EVENT_TYPES } from '@/types/sse.types'
import {
  parseAgentStatusChanged,
  parseAgentBubble,
  parseRunCompleted,
  parseRunError,
} from '@/lib/sseEventParser'

type ConnectionStatus = 'idle' | 'connected' | 'error'

export function useSSEListener(runId: string | null): { connectionStatus: ConnectionStatus } {
  const [connectionStatus, setConnectionStatus] = useState<ConnectionStatus>('idle')
  const esRef = useRef<EventSource | null>(null)

  useEffect(() => {
    if (!runId) {
      setConnectionStatus('idle')
      return
    }

    const es = new EventSource(`/api/runs/${runId}/stream`)
    esRef.current = es
    setConnectionStatus('connected')

    es.addEventListener(SSE_EVENT_TYPES.AGENT_STATUS_CHANGED, (e: MessageEvent) => {
      const payload = parseAgentStatusChanged(e.data)
      if (!payload) return
      useAgentStatusStore.getState().setAgentStatus(
        payload.agentId,
        payload.status,
        payload.step,
        payload.message,
      )
    })

    es.addEventListener(SSE_EVENT_TYPES.AGENT_BUBBLE, (e: MessageEvent) => {
      const payload = parseAgentBubble(e.data)
      if (!payload) return
      useAgentStatusStore.getState().setAgentBubble(payload.agentId, payload.message)
    })

    es.addEventListener(SSE_EVENT_TYPES.RUN_COMPLETED, (e: MessageEvent) => {
      const payload = parseRunCompleted(e.data)
      if (!payload) return
      useRunStore.getState().setRunCompleted(payload.duration, payload.runFolder)
      es.close()
      setConnectionStatus('idle')
    })

    es.addEventListener(SSE_EVENT_TYPES.RUN_ERROR, (e: MessageEvent) => {
      const payload = parseRunError(e.data)
      if (payload?.agentId) {
        useAgentStatusStore.getState().setAgentStatus(
          payload.agentId,
          'error',
          payload.step,
          payload.message,
        )
      }
      useRunStore.getState().setRunError(payload?.message ?? 'Erreur inconnue')
      es.close()
      setConnectionStatus('error')
    })

    es.onerror = () => {
      // EventSource reconnecte automatiquement (NFR5) — pas de code de retry explicite
      // Seule l'erreur fatale (CLOSED readyState) nécessite un traitement
      if (es.readyState === EventSource.CLOSED) {
        setConnectionStatus('error')
      }
    }

    return () => {
      es.close()
      esRef.current = null
    }
  }, [runId])

  return { connectionStatus }
}
```

### §EventSource — points critiques

**Named events SSE :** Le backend émet des events avec `event: agent.status.changed\n` — ceux-ci ne sont PAS reçus via `es.onmessage`. Il FAUT utiliser `es.addEventListener('agent.status.changed', handler)` — c'est l'erreur la plus fréquente avec les SSE nommés.

**Reconnexion automatique :** `EventSource` reconnecte automatiquement après déconnexion réseau. Le retry delay est contrôlé par l'en-tête `retry:` du serveur. Ne PAS ajouter de boucle de reconnexion manuelle — c'est redondant et peut créer des doublons d'EventSource.

**Fermeture explicite :** À `run.completed` et `run.error`, le serveur Laravel ferme le stream (`retry: 0` envoyé). Fermer aussi côté client avec `es.close()` pour éviter que le browser tente de reconnecter.

**Proxy Next.js (next.config.ts) :** Le rewrite `/api/*` → `http://localhost:8000/api/*` est configuré. Pour SSE, `next dev` (mode développement) est requis — le dev server supporte les connexions longues. `next build + next start` ne supporte pas les SSE via rewrites.

### §Guardrails — Erreurs critiques à éviter

| ❌ Interdit | ✅ Correct |
|---|---|
| `new EventSource(...)` dans un composant React | `useSSEListener(runId)` dans le composant, jamais `EventSource` direct |
| `es.onmessage` pour les events nommés | `es.addEventListener('agent.status.changed', ...)` |
| `state.agents[agentId].status = 'working'` | `set(state => ({ agents: { ...state.agents, [agentId]: { ...state.agents[agentId], status: 'working' } } }))` |
| Logique de transformation dans un store | Toute logique dans `useSSEListener` ou `lib/` |
| Retrier manuellement la connexion EventSource | EventSource reconnecte nativement — aucun code de retry |
| Appeler `useAgentStatusStore()` dans le hook (appel de hook dans hook) | `useAgentStatusStore.getState()` (accès store sans hook, valide hors render) |
| `'use client'` absent sur le hook | Requis car `useEffect` et `useState` |

### §Portée délimitée — Ce qui n'appartient PAS à cette story

- **Connexion `agentStatusStore` → `AgentDiagram`** : `AgentDiagram.tsx` lira `agentStatusStore` pour mettre à jour les nodes React Flow → Story 2.6
- **Appel de `useSSEListener` depuis un composant réel** : `LaunchBar` appellera `useSSEListener(runId)` après le POST → Story 2.7a
- **Rendu des `BubbleBox`** → Story 2.7b
- **`RunSummaryModal`** déclenchée par `run.completed` → Story 2.7b
- **`useRunHistory`** → Story 4.1

### §Apprentissages de la story 2.4 applicables

- Les 4 payloads SSE sont en **camelCase strict** avec timestamp ISO 8601 — ne pas attendre snake_case
- `run.completed` et `run.error` signalent la fin du stream — fermer `EventSource` après
- `agentId` peut être `''` dans `run.error` (bug connu reviewé en 2.4, deferred) — defensive guard dans le parser
- Le backend envoie `retry: 0` dans les events terminaux — cela désactive la reconnexion auto côté browser pour ces cas
- `step` est toujours `0` actuellement (granularité step-level prévue en Epic 3)

### §Zustand v5 — particularités

- API identique à v4 pour `create()` — pas de breaking change pour les stores simples
- `useAgentStatusStore.getState()` fonctionne toujours hors React (dans le hook useEffect) — pattern validé
- Le store `workflowStore.ts` existant utilise le même pattern — s'y référer pour la cohérence

### §AGENTS.md — Avertissement critique

Le fichier `frontend/AGENTS.md` avertit : **"This is NOT the Next.js you know — read the relevant guide in `node_modules/next/dist/docs/`"**. Avant toute modification de la config Next.js ou des patterns de routing/hooks, consulter ce dossier.

### Project Structure Notes

- Alignement avec la structure unifiée : `hooks/`, `stores/`, `types/`, `lib/` sous `frontend/src/`
- Nommage : hooks en camelCase avec préfixe `use`, stores avec suffixe `Store`, types en PascalCase
- Les stores exportent `useXxxStore` (pattern de `workflowStore.ts` existant)
- Les types SSE et Run sont dans `src/types/` — jamais inline dans les fichiers

### References

- [Source: docs/planning-artifacts/epics.md#Story-2.5] — User story, AC, portée
- [Source: docs/planning-artifacts/epics.md#Additional-Requirements] — useSSEListener hook exclusif, Zustand immuable, stores état seul
- [Source: docs/planning-artifacts/architecture.md#Frontend-Stack] — Next.js 16.2.2, Zustand ^5, EventSource native
- [Source: docs/planning-artifacts/architecture.md#Communication-Patterns] — Pattern useSSEListener, spread immutable, getState() hors render
- [Source: docs/planning-artifacts/architecture.md#Project-Structure] — Chemins exacts hooks/, stores/, types/, lib/
- [Source: docs/planning-artifacts/architecture.md#State-Boundary] — Stores mis à jour uniquement via useSSEListener pendant run actif
- [Source: docs/implementation-artifacts/2-4-stream-sse-evenements-temps-reel-laravel-next-js.md#Dev-Notes] — Payloads SSE exacts, event types, schémas PHP correspondants
- [Source: docs/implementation-artifacts/2-4-stream-sse-evenements-temps-reel-laravel-next-js.md#Guardrails] — useSSEListener hook custom uniquement (story 2.5)
- [Source: frontend/src/stores/workflowStore.ts] — Pattern Zustand existant à reproduire
- [Source: frontend/src/hooks/useWorkflows.ts] — Pattern hook existant (useCallback, useEffect, cleanup)
- [Source: frontend/next.config.ts] — Proxy `/api/*` → `localhost:8000` confirmé

## Dev Agent Record

### Agent Model Used

claude-sonnet-4-6

### Debug Log References

- ESLint `react-hooks/set-state-in-effect` : `setConnectionStatus` appelé synchronement dans le corps de `useEffect` — résolu en déplaçant dans `es.onopen` callback et en retirant l'appel inutile du branch `!runId`

### Completion Notes List

- `sse.types.ts` : 4 interfaces event SSE + constante `SSE_EVENT_TYPES` + type union `SseEventType`
- `run.types.ts` : types `AgentState`, `RunState`, `AgentStatus`, `RunStatus`
- `lib/sseEventParser.ts` : 4 fonctions de parsing type-safe sans lib externe — retournent `null` sur payload invalide
- `agentStatusStore.ts` : store Zustand avec spread immutable, `DEFAULT_AGENT_STATE` pour init des nouveaux agents
- `runStore.ts` : store Zustand état run courant (runId, status, duration, runFolder, errorMessage)
- `useSSEListener.ts` : hook `'use client'` — `EventSource` via `addEventListener` pour named events, `onopen` pour `connectionStatus`, cleanup dans return de `useEffect`, `getState()` pour accès stores hors render
- TypeScript : 0 erreur — `npx tsc --noEmit`
- ESLint : 0 erreur — `eslint src/`

### File List

- frontend/src/types/sse.types.ts (créé)
- frontend/src/types/run.types.ts (créé)
- frontend/src/lib/sseEventParser.ts (créé)
- frontend/src/stores/agentStatusStore.ts (créé)
- frontend/src/stores/runStore.ts (créé)
- frontend/src/hooks/useSSEListener.ts (créé)
