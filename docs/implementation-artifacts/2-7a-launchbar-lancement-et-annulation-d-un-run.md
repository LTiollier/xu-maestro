# Story 2.7a : LaunchBar — lancement et annulation d'un run

Status: done

## Story

As a développeur,
I want une barre de lancement fixée en bas de l'interface pour soumettre un brief et annuler un run en cours,
So that je peux déclencher et contrôler l'exécution sans quitter la vue principale.

## Acceptance Criteria

1. **Given** la page chargée sans workflow sélectionné — **When** la `LaunchBar` s'affiche — **Then** elle est en état `disabled` : `Textarea` et bouton "Lancer" sont disabled

2. **Given** un workflow sélectionné, aucun run actif — **When** la `LaunchBar` s'affiche — **Then** elle est en état `ready` : `Textarea` activé (placeholder : "Décris la tâche à confier à l'équipe..."), bouton "Lancer" primaire visible

3. **Given** la `LaunchBar` en état `ready` — **When** je clique "Lancer" avec un brief non-vide — **Then** `POST /api/runs { workflowFile, brief }` est appelé — **And** la `LaunchBar` passe en état `running` : `Textarea` disabled, bouton "Lancer" remplacé par "Annuler" (destructif) — **And** le `runStore` est mis à jour avec le `runId` retourné

4. **Given** la `LaunchBar` en état `ready` — **When** je clique "Lancer" et que Laravel retourne HTTP 422 `{ message, code: "YAML_INVALID" }` — **Then** la `LaunchBar` reste en état `ready` — aucun run ne démarre — **And** un message d'erreur inline apparaît dans la `LaunchBar`, au-dessus du `Textarea` : texte du champ `message` retourné par l'API — **And** le message disparaît dès que l'utilisateur sélectionne un autre workflow ou clique à nouveau "Lancer"

5. **Given** la `LaunchBar` en état `running` — **When** je clique "Annuler" — **Then** `DELETE /api/runs/{id}` est appelé — **And** la `LaunchBar` repasse en état `ready`

6. **Given** la `LaunchBar` en état `running` — **When** un événement `run.completed` ou `run.error` est reçu via SSE — **Then** la `LaunchBar` repasse en état `ready` automatiquement

7. **And** la `LaunchBar` est fixée en bas (`fixed bottom-0`), hauteur ~80px, toujours visible — **And** le bouton "Lancer" est accessible au clavier, focus visible

## Tasks / Subtasks

- [x] **T1 — Créer `LaunchBar.tsx`** (AC 1–7)
  - [x] Créer `frontend/src/components/LaunchBar.tsx` avec `'use client'`
  - [x] Implémenter les 3 états (disabled / ready / running) dérivés de `selectedWorkflow` et `runStore.status`
  - [x] État local : `brief` (string), `errorState` (ErrorState | null) avec dérivation sans useEffect
  - [x] Appeler `useSSEListener(runId)` dans le composant
  - [x] Handler "Lancer" : réinitialiser erreur + `resetAgents()` + `POST /api/runs` + `setRunId` ou afficher erreur 422
  - [x] Handler "Annuler" : `DELETE /api/runs/{runId}` + `resetRun()`
  - [x] Retour automatique à `ready` quand `status` passe à `'completed'` ou `'error'`

- [x] **T2 — Intégrer dans `page.tsx`** (AC 7)
  - [x] Importer `LaunchBar` dans `frontend/src/app/page.tsx`
  - [x] Remplacer le placeholder `<footer>` par `<LaunchBar />`

- [x] **T3 — Vérification manuelle** (AC 1–7)
  - [x] `npx tsc --noEmit` : 0 erreur
  - [x] `eslint src/` : 0 erreur, 0 warning

### Review Findings (2026-04-08)

- [x] [Review][Decision] AC7 : `fixed bottom-0` vs layout flex — décision : conserver le layout flex `shrink-0` (comportement identique pour une SPA `h-full`) — deferred
- [x] [Review][Patch] Double-clic race : aucun garde in-flight dans `handleLancer` — résolu : ajout de `isSubmitting` state, bouton disabled pendant le fetch [LaunchBar.tsx:handleLancer]
- [x] [Review][Patch] `handleLancer` : pas de try/catch sur `fetch` — résolu : try/catch avec message d'erreur réseau affiché [LaunchBar.tsx:handleLancer]
- [x] [Review][Patch] `handleAnnuler` : pas de try/catch — résolu : try/catch + `resetRun()` uniquement si `res.ok`, message d'erreur sinon [LaunchBar.tsx:handleAnnuler]
- [x] [Review][Patch] `runId` non validé sur la réponse POST — résolu : guard `if (!newRunId)` avec message d'erreur [LaunchBar.tsx:handleLancer]
- [x] [Review][Patch] AC4 : `errorState` masqué mais non effacé au changement de workflow — résolu : pattern getDerivedStateFromProps, efface l'erreur dès changement de workflow [LaunchBar.tsx]
- [x] [Review][Defer] Race SSE/annulation : `run.completed` peut arriver avant que `resetRun()` ne s'exécute lors d'un clic "Annuler" — déferred, contrainte architecturale concurrente [LaunchBar.tsx] — deferred
- [x] [Review][Defer] `WorkflowSelector` non désactivé pendant un run actif — changement de workflow en cours de run laisse un état incohérent [WorkflowSelector.tsx] — deferred, pre-existing
- [x] [Review][Defer] États agents (`agentStatusStore`) non réinitialisés après `run.completed`/`run.error` — persistance visuelle jusqu'au prochain lancement — deferred, scope 2.7b

## Dev Notes

### §Fichiers à créer / modifier

```
frontend/src/components/LaunchBar.tsx   ← CRÉER
frontend/src/app/page.tsx               ← MODIFIER (import + replace placeholder footer)
```

**Aucun autre fichier à toucher.** Tous les stores, hooks SSE et types sont déjà en place.

---

### §Structure du composant `LaunchBar.tsx`

```typescript
'use client'

import { useState } from 'react'
import { useWorkflowStore } from '@/stores/workflowStore'
import { useRunStore } from '@/stores/runStore'
import { useAgentStatusStore } from '@/stores/agentStatusStore'
import { useSSEListener } from '@/hooks/useSSEListener'
import { Textarea } from '@/components/textarea'
import { Button } from '@/components/button'

type LaunchBarState = 'disabled' | 'ready' | 'running'

export function LaunchBar() {
  const { selectedWorkflow } = useWorkflowStore()
  const { runId, status, setRunId, resetRun } = useRunStore()
  const [brief, setBrief] = useState('')
  const [errorMessage, setErrorMessage] = useState<string | null>(null)

  // SSE démarré dès qu'un runId existe (AC 6 — retour auto à ready via store)
  useSSEListener(runId)

  const launchBarState: LaunchBarState =
    !selectedWorkflow ? 'disabled'
    : status === 'running' ? 'running'
    : 'ready'

  const handleLancer = async () => {
    if (!selectedWorkflow || !brief.trim()) return
    setErrorMessage(null)
    useAgentStatusStore.getState().resetAgents()

    const res = await fetch('/api/runs', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ workflowFile: selectedWorkflow.file, brief }),
    })

    if (!res.ok) {
      const body = await res.json()
      setErrorMessage(body.message ?? 'Erreur inconnue')
      return
    }

    const { runId: newRunId } = await res.json()
    setRunId(newRunId)
  }

  const handleAnnuler = async () => {
    if (!runId) return
    await fetch(`/api/runs/${runId}`, { method: 'DELETE' })
    resetRun()
  }

  return (
    <footer className="h-20 shrink-0 border-t border-zinc-700 bg-zinc-900 flex flex-col justify-center px-4 gap-2">
      {errorMessage && (
        <p className="text-red-400 text-xs">{errorMessage}</p>
      )}
      <div className="flex items-center gap-3">
        <Textarea
          value={brief}
          onChange={(e) => setBrief(e.target.value)}
          disabled={launchBarState !== 'ready'}
          placeholder="Décris la tâche à confier à l'équipe..."
          className="resize-none h-10 text-sm"
          rows={1}
        />
        {launchBarState === 'running' ? (
          <Button variant="destructive" onClick={handleAnnuler}>
            Annuler
          </Button>
        ) : (
          <Button
            onClick={handleLancer}
            disabled={launchBarState === 'disabled' || !brief.trim()}
          >
            Lancer
          </Button>
        )}
      </div>
    </footer>
  )
}
```

---

### §Modification dans `page.tsx`

**Avant :**

```tsx
import { WorkflowSelector } from '@/components/WorkflowSelector'
import { AgentDiagram } from '@/components/AgentDiagram'
import { useWorkflowStore } from '@/stores/workflowStore'

// ...
      <footer className="h-20 shrink-0 border-t border-zinc-700 bg-zinc-900 flex items-center px-4 gap-3">
        {/* Story 2.7a : LaunchBar */}
        <p className="text-zinc-400 text-sm">LaunchBar (Story 2.7a)</p>
      </footer>
```

**Après :**

```tsx
import { WorkflowSelector } from '@/components/WorkflowSelector'
import { AgentDiagram } from '@/components/AgentDiagram'
import { LaunchBar } from '@/components/LaunchBar'
import { useWorkflowStore } from '@/stores/workflowStore'

// ...
      <LaunchBar />
```

Le `<footer>` existant dans `page.tsx` est supprimé car `LaunchBar` encapsule son propre `<footer>`.

---

### §Dérivation de l'état LaunchBar — logique complète

| `selectedWorkflow` | `runStore.status` | `launchBarState` |
|---|---|---|
| `null` | (peu importe) | `'disabled'` |
| non-null | `'running'` | `'running'` |
| non-null | `'idle'` \| `'completed'` \| `'error'` | `'ready'` |

**Retour automatique à `ready` (AC 6) :** `useSSEListener` appelle déjà `setRunCompleted` (status → `'completed'`) et `setRunError` (status → `'error'`). La dérivation ci-dessus suffit — aucun `useEffect` explicite nécessaire.

---

### §Handler "Lancer" — détails

1. `setErrorMessage(null)` — efface le message d'erreur précédent (AC 4 : "disparaît quand on clique Lancer")
2. `useAgentStatusStore.getState().resetAgents()` — remet les agents à `idle` pour le nouveau run (le diagramme sera propre)
3. `POST /api/runs` — payload : `{ workflowFile: selectedWorkflow.file, brief }`
4. Si HTTP 422 : `setErrorMessage(body.message)` — la LaunchBar reste `ready`
5. Si succès : `setRunId(newRunId)` → `runStore.status` passe à `'running'` → `launchBarState` dérivé à `'running'` → `useSSEListener` démarre avec le nouveau `runId`

**Attention :** `selectedWorkflow.file` est le champ `file` du type `Workflow` (pas `name`). Vérifier dans `workflow.types.ts` avant d'écrire.

---

### §Handler "Annuler" — détails

1. `DELETE /api/runs/{runId}` — pas d'attente de réponse obligatoire (fire and forget acceptable)
2. `resetRun()` — efface `runId`, `status` → `'idle'`, `duration`, `runFolder`, `errorMessage` dans le store
3. `launchBarState` dérive automatiquement à `'ready'`

**Ne pas appeler `resetAgents()` ici** — le diagramme garde l'état partiel pour visibilité post-annulation (2.7b s'en occupera si nécessaire).

---

### §useSSEListener — placement dans LaunchBar

`useSSEListener(runId)` est appelé directement dans `LaunchBar`. Quand `runId` est `null`, le hook ne fait rien (guard `if (!runId) return` dans le useEffect). Dès que `setRunId(newRunId)` est appelé, le hook démarre le stream SSE automatiquement.

**Pattern correct :**

```typescript
const { runId, status, setRunId, resetRun } = useRunStore()
useSSEListener(runId)  // démarre/arrête selon runId
```

`useSSEListener` était précédemment non connecté à un composant actif (les stores étaient peuplés manuellement pour les tests en 2.6). C'est la première fois qu'il est branché à une action utilisateur réelle.

---

### §Effacement de l'erreur (AC 4)

L'erreur s'efface dans deux cas :
1. **Clic "Lancer"** — `setErrorMessage(null)` en tête du handler
2. **Changement de workflow** — surveiller via `useEffect([selectedWorkflow], () => setErrorMessage(null))`

Ajouter ce `useEffect` dans le composant :

```typescript
useEffect(() => {
  setErrorMessage(null)
}, [selectedWorkflow])
```

---

### §Composants UI à utiliser

- `Textarea` → `@/components/textarea` (shadcn, déjà installé en 1.2)
- `Button` → `@/components/button` (shadcn, variantes : default, destructive)

Les imports sont en lowercase car shadcn génère les composants avec des noms de fichiers en lowercase.

---

### §Guardrails — Erreurs critiques à éviter

| ❌ Interdit | ✅ Correct |
|---|---|
| Appeler `new EventSource()` directement dans LaunchBar | Utiliser uniquement `useSSEListener(runId)` |
| Logique de transformation dans les stores | Uniquement dans les hooks ou `lib/` |
| Mutation directe du state Zustand | Spread immutable via les setters du store |
| `selectedWorkflow.name` comme `workflowFile` | `selectedWorkflow.file` (le champ YAML filename) |
| Garder le `<footer>` de `page.tsx` en plus de LaunchBar | LaunchBar encapsule son propre `<footer>` — supprimer le placeholder |
| `resetAgents()` à l'annulation | Seulement au lancement (run propre dès le début, pas après) |

---

### §Scope délimité — Ce qui N'appartient PAS à cette story

- **`BubbleBox` sous les `AgentCard`** — Story 2.7b
- **Sidebar log de session** — Story 2.7b
- **`RunSummaryModal` à `run.completed`** — Story 2.7b
- **Affichage de la durée ou du dossier run** — Story 2.7b
- **Retry depuis checkpoint** — Epic 3

---

### §Notes de la story précédente (2.6)

- `AgentDiagram.tsx` : `resetAgents()` jamais appelé sur `setSelectedWorkflow` (bug identifié mais deferred) — LaunchBar doit appeler `resetAgents()` avant un nouveau run pour éviter les statuts périmés
- React Flow en mode contrôlé — aucun re-mount pendant un run
- Pattern Zustand : subscription via `useXxxStore()` (objet déstructuré), mises à jour via spread immutable

---

### §Structure des types

```typescript
// workflow.types.ts — champs pertinents
interface Workflow {
  file: string  // ← à utiliser comme `workflowFile` dans POST /api/runs
  name: string
  agents: Agent[]
}

// run.types.ts
type RunStatus = 'idle' | 'running' | 'completed' | 'error'
```

### Project Structure Notes

- `LaunchBar.tsx` dans `frontend/src/components/` — PascalCase, cohérent avec les composants existants
- Alias `@/` = `frontend/src/`
- Lire `frontend/AGENTS.md` → `frontend/CLAUDE.md` avant toute hypothèse sur les APIs Next.js

### References

- [Source: docs/planning-artifacts/epics.md#Story-2.7a] — User story, ACs complets
- [Source: docs/planning-artifacts/architecture.md#API-&-Communication-Patterns] — Endpoints REST, format d'erreur, SSE
- [Source: docs/planning-artifacts/architecture.md#Frontend-Architecture] — Stores Zustand, useSSEListener, patterns
- [Source: docs/planning-artifacts/architecture.md#Implementation-Patterns] — Naming conventions, structure fichiers
- [Source: docs/planning-artifacts/ux-design-specification.md#LaunchBar] — UX-DR6 : 3 états, fixed bottom, Textarea, Button
- [Source: frontend/src/stores/runStore.ts] — setRunId, resetRun, status
- [Source: frontend/src/stores/workflowStore.ts] — selectedWorkflow
- [Source: frontend/src/stores/agentStatusStore.ts] — resetAgents()
- [Source: frontend/src/hooks/useSSEListener.ts] — signature, runId null guard, SSE events
- [Source: frontend/src/app/page.tsx] — placeholder footer à remplacer
- [Source: docs/implementation-artifacts/2-6-diagramme-anime-etats-temps-reel-et-transitions-de-handoff.md#Review-Findings] — resetAgents() jamais appelé, learning pour 2.7a

## Dev Agent Record

### Agent Model Used

claude-sonnet-4-6

### Debug Log References

- ESLint `react-hooks/set-state-in-effect` sur `useEffect(() => setErrorMessage(null), [selectedWorkflow])` — résolu en remplaçant par un état `ErrorState { workflowFile, message }` et une dérivation sans effet : l'erreur ne s'affiche que si le `workflowFile` stocké correspond au workflow actuellement sélectionné

### Completion Notes List

- `LaunchBar.tsx` créé : états `disabled`/`ready`/`running` dérivés de `selectedWorkflow` et `runStore.status`
- `useSSEListener(runId)` branché dans `LaunchBar` — première fois que le hook est connecté à une action utilisateur réelle (précédemment uniquement testé manuellement)
- Effacement d'erreur sans `useEffect` : `ErrorState { workflowFile, message }` — l'erreur 422 disparaît automatiquement si le workflow change
- `resetAgents()` appelé avant chaque nouveau run pour remettre le diagramme à zéro
- `page.tsx` : placeholder `<footer>` remplacé par `<LaunchBar />`
- 0 erreur TypeScript, 0 erreur ESLint

### File List

- frontend/src/components/LaunchBar.tsx (créé)
- frontend/src/app/page.tsx (modifié)
