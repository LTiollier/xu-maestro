# Story 1.5 : Diagramme statique des agents d'un workflow sélectionné

Status: done
Epic: 1
Story: 5
Date: 2026-04-04

## Story

As a développeur,
I want voir les agents du workflow sélectionné dans un pipeline vertical de cards avec leur état `idle`,
so that je visualise mon équipe avant de lancer un run.

## Acceptance Criteria

1. **Given** un workflow sélectionné dans le sélecteur — **When** le diagramme se charge — **Then** le composant `<ReactFlow>` est monté avec les nodes et edges générés depuis la config workflow du `workflowStore` (FR5)
2. **Given** le diagramme monté — **Then** chaque agent est rendu comme un custom node React Flow de type `agentCard` — le composant `AgentCard` (base `Card` shadcn) reçoit `name`, `engine`, `steps[]` et `status: "idle"` via `data` props (FR5, UX-DR1)
3. **Given** chaque `AgentCard` rendue — **Then** un `Badge` `idle` (zinc-500, opacité 45%) est affiché et chaque étape est listée sous forme de `StepItem` (icône ○, statut `pending`) (UX-DR2)
4. **Given** les agents reliés — **Then** chaque paire consécutive est connectée par un custom edge React Flow de type `pipelineConnector` en état `inactive` (zinc-700) (UX-DR3)
5. **Given** le `<ReactFlow>` configuré — **Then** il est configuré avec `fitView`, et toutes les interactions désactivées (`nodesDraggable={false}`, `nodesConnectable={false}`, `panOnDrag={false}`, `zoomOnScroll={false}`) — pipeline read-only
6. **When** je change de workflow dans le sélecteur — **Then** les nodes et edges du `<ReactFlow>` sont recalculés depuis le nouveau YAML — le diagramme se reconfigure instantanément (FR5)
7. **Given** l'état final — **Then** les cards sont max-width `2xl`, centrées via `fitView`, et le rendu reste fluide jusqu'à 5 agents simultanés (NFR3)

## Tasks / Subtasks

- [x] **T1 — Exposer `steps[]` dans l'API Laravel** (prérequis AC: 2, 3)
  - [x] Mettre à jour `backend/app/Http/Resources/WorkflowResource.php` : ajouter `'steps' => $agent['steps'] ?? []` dans le mapping des agents
  - [x] Vérifier que le test `WorkflowApiTest` ou `WorkflowControllerTest` couvre `steps` dans la réponse — ajouter l'assertion si manquante

- [x] **T2 — Mettre à jour les types TypeScript** (prérequis AC: 1, 2)
  - [x] Ouvrir `frontend/src/types/workflow.types.ts`
  - [x] Ajouter `steps: string[]` à l'interface `Agent` (après `timeout`)
  - [x] Vérifier que `workflowStore.ts` et `useWorkflows.ts` n'ont pas besoin de modification (ils utilisent le type `Workflow` qui contient `Agent[]`)

- [x] **T3 — Installer `@xyflow/react`** (prérequis React Flow v12)
  - [x] `npm install @xyflow/react` depuis `frontend/`
  - [x] Vérifier que `@xyflow/react` apparaît dans `package.json` dependencies

- [x] **T4 — Créer le composant `StepItem`** (AC: 3)
  - [x] Créer `frontend/src/components/StepItem.tsx`
  - [x] Props : `label: string`, `status: 'pending' | 'working' | 'done' | 'error'`
  - [x] Icônes : ○ (pending), ⚙ (working), ✓ (done), ✗ (error)
  - [x] `'use client'` en tête de fichier

- [x] **T5 — Créer le composant `AgentCard`** (custom node React Flow, type `agentCard`) (AC: 2, 3)
  - [x] Créer `frontend/src/components/AgentCard.tsx`
  - [x] `'use client'` en tête de fichier
  - [x] Définir l'interface `AgentCardData` avec `name`, `engine`, `steps`, `status`
  - [x] Importer `Handle` et `Position` depuis `@xyflow/react` pour les connecteurs haut/bas
  - [x] Rendre `Card` shadcn avec header (nom + engine + `Badge`) + liste de `StepItem`
  - [x] Appliquer les styles `idle` (opacité 45% via `opacity-45` ou classe utilitaire)
  - [x] Handles invisibles : `className="opacity-0 pointer-events-none"`

- [x] **T6 — Créer le composant `DiagramEdge`** (custom edge React Flow, type `pipelineConnector`) (AC: 4)
  - [x] Créer `frontend/src/components/DiagramEdge.tsx`
  - [x] `'use client'` en tête de fichier
  - [x] Importer `BaseEdge`, `getStraightPath`, `EdgeProps` depuis `@xyflow/react`
  - [x] Couleur selon `data.status` : `inactive` → zinc-700 (`#3f3f46`), `active` → blue-400 (`#60a5fa`), `done` → emerald-500 (`#10b981`)
  - [x] `strokeWidth: 2`

- [x] **T7 — Créer le composant `AgentDiagram`** (React Flow wrapper) (AC: 1, 5, 6, 7)
  - [x] Créer `frontend/src/components/AgentDiagram.tsx`
  - [x] `'use client'` en tête de fichier
  - [x] Importer `import '@xyflow/react/dist/style.css'`
  - [x] Définir `nodeTypes` et `edgeTypes` en **dehors** du composant (références stables — voir §CRITIQUE)
  - [x] Lire `selectedWorkflow` depuis `useWorkflowStore`
  - [x] Calculer `nodes` et `edges` via `useMemo` sur `selectedWorkflow`
  - [x] Positionner les nœuds verticalement : `position: { x: 0, y: index * 220 }`
  - [x] Configurer `<ReactFlow>` read-only (`fitView`, toutes interactions désactivées)
  - [x] Wrap dans `<ReactFlowProvider>` (requis pour Story 2.6 qui utilisera `useReactFlow().setNodes()`)
  - [x] Conteneur de hauteur explicite (voir §AgentDiagram-height)

- [x] **T8 — Intégrer `AgentDiagram` dans `page.tsx`** (AC: 1, 6)
  - [x] Remplacer `<p className="text-zinc-400 text-sm">Diagramme agents (Story 1.5)</p>` par `<AgentDiagram />`
  - [x] Adapter le wrapper div pour avoir une hauteur explicite (voir §page-integration)
  - [x] Ne pas modifier `<aside>`, `<footer>` ni aucun autre placeholder

---

## Dev Notes

### §CRITIQUE — Prérequis obligatoires avant tout code

**`@xyflow/react` N'EST PAS installé.** C'est le package React Flow v12 (successor de `reactflow` v11). Le nom du package a changé.

```bash
cd frontend && npm install @xyflow/react
```

**`steps[]` N'EST PAS dans l'API.** `WorkflowResource` ne retourne actuellement que `id`, `engine`, `timeout` par agent. L'AC exige `steps[]` dans les props `AgentCard`. Mettre à jour le backend **en premier**.

**`steps[]` N'EST PAS dans les types TypeScript.** `workflow.types.ts` → interface `Agent` n'a pas `steps: string[]`.

**Ordre impératif des tâches : T1 (backend) → T2 (types) → T3 (npm) → T4-T8 (composants).**

---

### §WorkflowResource — Modification exacte

```php
// backend/app/Http/Resources/WorkflowResource.php
'agents' => array_map(fn ($agent) => [
    'id'      => $agent['id'],
    'engine'  => $agent['engine'],
    'timeout' => (int) ($agent['timeout'] ?? config('xu-workflow.default_timeout')),
    'steps'   => $agent['steps'] ?? [],   // ← AJOUTER
], $this->resource['agents'] ?? []),
```

Le YAML `example.yaml` a déjà `steps: ["Étape 1 — Analyser le brief"]`. `YamlService` les charge déjà — il suffit d'exposer le champ.

---

### §Types TypeScript — Modification exacte

```typescript
// frontend/src/types/workflow.types.ts
export interface Agent {
  id: string
  engine: string
  timeout: number
  steps: string[]   // ← AJOUTER
}

export interface Workflow {
  name: string
  file: string
  agents: Agent[]
}
```

Aucune modification de `workflowStore.ts` ou `useWorkflows.ts` — ils propagent déjà `Agent[]` tel quel.

---

### §CRITIQUE — nodeTypes et edgeTypes DOIVENT être stables

**Piège React Flow classique :** définir `nodeTypes` ou `edgeTypes` à l'intérieur du composant parent déclenche une boucle de re-rendu infinie.

```typescript
// ✅ CORRECT — en dehors du composant
const nodeTypes = { agentCard: AgentCard }
const edgeTypes = { pipelineConnector: DiagramEdge }

export function AgentDiagram() {
  // utiliser nodeTypes et edgeTypes ici
}

// ❌ INTERDIT — à l'intérieur du composant
export function AgentDiagram() {
  const nodeTypes = { agentCard: AgentCard } // re-créé à chaque render → boucle infinie
}
```

---

### §AgentDiagram — Implémentation exacte

```tsx
// frontend/src/components/AgentDiagram.tsx
'use client'

import { useMemo } from 'react'
import { ReactFlow, ReactFlowProvider } from '@xyflow/react'
import '@xyflow/react/dist/style.css'
import { useWorkflowStore } from '@/stores/workflowStore'
import { AgentCard } from './AgentCard'
import { DiagramEdge } from './DiagramEdge'

const nodeTypes = { agentCard: AgentCard }
const edgeTypes = { pipelineConnector: DiagramEdge }

function AgentDiagramInner() {
  const { selectedWorkflow } = useWorkflowStore()

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
        status: 'idle' as const,
      },
    }))

    const edges = selectedWorkflow.agents.slice(0, -1).map((agent, index) => ({
      id: `${agent.id}-to-${selectedWorkflow.agents[index + 1].id}`,
      source: agent.id,
      target: selectedWorkflow.agents[index + 1].id,
      type: 'pipelineConnector' as const,
      data: { status: 'inactive' },
    }))

    return { nodes, edges }
  }, [selectedWorkflow])

  const containerHeight = selectedWorkflow
    ? Math.max(400, selectedWorkflow.agents.length * 220 + 80)
    : 400

  return (
    <div style={{ height: containerHeight }} className="w-full">
      <ReactFlow
        nodes={nodes}
        edges={edges}
        nodeTypes={nodeTypes}
        edgeTypes={edgeTypes}
        fitView
        nodesDraggable={false}
        nodesConnectable={false}
        panOnDrag={false}
        zoomOnScroll={false}
        preventScrolling={false}
        proOptions={{ hideAttribution: true }}
        className="bg-zinc-950"
      />
    </div>
  )
}

export function AgentDiagram() {
  return (
    <ReactFlowProvider>
      <AgentDiagramInner />
    </ReactFlowProvider>
  )
}
```

**Pourquoi `ReactFlowProvider` dès maintenant :** Story 2.6 utilisera `useReactFlow().setNodes()` pour les transitions temps réel. Le Provider doit wrapper le composant qui utilise ces hooks.

**`preventScrolling={false}` :** empêche React Flow de capturer le scroll de la page (important car `<main>` a `overflow-auto`).

---

### §AgentCard — Implémentation exacte

```tsx
// frontend/src/components/AgentCard.tsx
'use client'

import { Handle, Position } from '@xyflow/react'
import { Card, CardContent, CardHeader } from '@/components/ui/card'
import { Badge } from '@/components/ui/badge'
import { StepItem } from './StepItem'
import { cn } from '@/lib/utils'

interface AgentCardData {
  name: string
  engine: string
  steps: string[]
  status: 'idle' | 'working' | 'done' | 'error'
}

const statusStyles: Record<AgentCardData['status'], string> = {
  idle:    'opacity-45 border-zinc-700',
  working: 'border-blue-500 shadow-[0_0_12px_rgba(59,130,246,0.4)]',
  done:    'opacity-70 border-emerald-500',
  error:   'border-red-500 animate-pulse',
}

const badgeStyles: Record<AgentCardData['status'], string> = {
  idle:    'bg-zinc-500/45 text-zinc-100',
  working: 'bg-blue-500 text-white',
  done:    'bg-emerald-500 text-white',
  error:   'bg-red-500 text-white',
}

export function AgentCard({ data }: { data: AgentCardData }) {
  return (
    <div className={cn('relative w-72', statusStyles[data.status])}>
      <Handle
        type="target"
        position={Position.Top}
        className="opacity-0 pointer-events-none"
      />
      <Card className="bg-zinc-900 border-inherit w-full">
        <CardHeader className="flex flex-row items-center justify-between gap-2 pb-2 pt-3 px-4">
          <div className="flex flex-col min-w-0">
            <span className="text-sm font-medium text-zinc-100 truncate">{data.name}</span>
            <span className="text-xs text-zinc-400">{data.engine}</span>
          </div>
          <Badge className={cn('shrink-0 text-xs', badgeStyles[data.status])}>
            {data.status}
          </Badge>
        </CardHeader>
        {data.steps.length > 0 && (
          <CardContent className="pt-0 pb-3 px-4">
            <div className="flex flex-col gap-0.5">
              {data.steps.map((step, i) => (
                <StepItem key={i} label={step} status="pending" />
              ))}
            </div>
          </CardContent>
        )}
      </Card>
      <Handle
        type="source"
        position={Position.Bottom}
        className="opacity-0 pointer-events-none"
      />
    </div>
  )
}
```

**Notes importantes :**
- `w-72` (288px) = taille fixe pour que `fitView` fonctionne correctement
- `border-inherit` sur `Card` pour que la couleur de bordure du parent s'applique
- Les Handles React Flow doivent être présents (même invisibles) pour que les edges se connectent

---

### §StepItem — Implémentation exacte

```tsx
// frontend/src/components/StepItem.tsx
'use client'

type StepStatus = 'pending' | 'working' | 'done' | 'error'

const icons: Record<StepStatus, string> = {
  pending: '○',
  working: '⚙',
  done:    '✓',
  error:   '✗',
}

const textColors: Record<StepStatus, string> = {
  pending: 'text-zinc-500',
  working: 'text-blue-400',
  done:    'text-emerald-400',
  error:   'text-red-400',
}

export function StepItem({ label, status }: { label: string; status: StepStatus }) {
  return (
    <div className={`flex items-center gap-2 text-xs py-0.5 ${textColors[status]}`}>
      <span className="shrink-0 w-3 text-center">{icons[status]}</span>
      <span className="truncate">{label}</span>
    </div>
  )
}
```

---

### §DiagramEdge — Implémentation exacte

```tsx
// frontend/src/components/DiagramEdge.tsx
'use client'

import { BaseEdge, getStraightPath } from '@xyflow/react'
import type { EdgeProps } from '@xyflow/react'

const edgeColors: Record<string, string> = {
  inactive: '#3f3f46', // zinc-700
  active:   '#60a5fa', // blue-400
  done:     '#10b981', // emerald-500
}

export function DiagramEdge({
  id,
  sourceX,
  sourceY,
  targetX,
  targetY,
  data,
}: EdgeProps) {
  const [edgePath] = getStraightPath({ sourceX, sourceY, targetX, targetY })
  const color = edgeColors[(data?.status as string) ?? 'inactive'] ?? edgeColors.inactive

  return (
    <BaseEdge
      id={id}
      path={edgePath}
      style={{ stroke: color, strokeWidth: 2, transition: 'stroke 300ms' }}
    />
  )
}
```

**Transition CSS 300ms :** conformément à UX-DR3 (changement d'état avec transition `background-color` 300ms).

---

### §page-integration — Modification page.tsx

```tsx
// frontend/src/app/page.tsx — seul changement nécessaire
// Remplacer dans le bloc selectedWorkflow ? :
<div className="w-full max-w-2xl">
  {/* Story 1.5 : AgentDiagram */}
  <p className="text-zinc-400 text-sm">Diagramme agents (Story 1.5)</p>
</div>

// Par :
<div className="w-full max-w-2xl">
  <AgentDiagram />
</div>
```

Import à ajouter en tête de `page.tsx` :
```tsx
import { AgentDiagram } from '@/components/AgentDiagram'
```

**Ne pas modifier** : `<aside>` (Story 2.7b), `<footer>` (Story 2.7a), le bloc `selectedWorkflow === null`, le `useWorkflowStore`, `WorkflowSelector`.

---

### §Tokens Tailwind disponibles

Tailwind v4 — config dans `globals.css` via `@theme inline` :
```css
--color-agent-idle:    #71717a;   /* zinc-500 */
--color-agent-working: #3b82f6;   /* blue-500 */
--color-agent-done:    #10b981;   /* emerald-500 */
--color-agent-error:   #ef4444;   /* red-500 */
```
→ Utiliser `text-agent-idle`, `bg-agent-working`, etc. (classes utilitaires opérationnelles)

**Rappel Tailwind v4 :** pas de `tailwind.config.ts`. PAS de classes dynamiques construites par concaténation de string (`'text-' + status`) — Tailwind purge les classes non-littérales.

---

### §CSS React Flow — Import obligatoire

```tsx
import '@xyflow/react/dist/style.css'
```

Sans cet import, les nœuds n'ont pas de positionnement (ils se superposent tous à l'origine). **À mettre dans `AgentDiagram.tsx`** uniquement (un seul import suffit).

---

### §Guardrails — Erreurs à ne pas commettre

| ❌ Interdit | ✅ Correct |
|---|---|
| `import { ReactFlow } from 'reactflow'` (v11) | `import { ReactFlow } from '@xyflow/react'` (v12) |
| Oublier `import '@xyflow/react/dist/style.css'` | Mettre l'import CSS dans `AgentDiagram.tsx` |
| Définir `nodeTypes` à l'intérieur du composant | Définir `nodeTypes` en dehors du composant |
| Pas de `ReactFlowProvider` | Wrapper avec `ReactFlowProvider` (nécessaire dès maintenant pour Story 2.6) |
| Pas de hauteur explicite sur le conteneur React Flow | `style={{ height: ... }}` calculé dynamiquement |
| `data.name` = nom inconnu | `data.name` = `agent.id` du YAML (c'est le seul identifiant disponible) |
| `steps[]` hardcodé dans les nodes | `steps[]` vient de l'API Laravel via `agent.steps` |
| Modifier `src/components/ui/` | Ne jamais modifier les composants shadcn |
| `className="bg-zinc-500/45"` pour l'opacité | Utiliser `opacity-45` sur le wrapper, pas `bg-opacity` (Tailwind v4) |
| Importer depuis `@radix-ui/react-*` | Shadcn de ce projet utilise `@base-ui/react` |
| Mettre `'use client'` autre qu'en **première ligne** | `'use client'` doit être la toute première ligne |
| Construire des classes dynamiques : `'bg-' + status + '-500'` | Utiliser un objet de mapping statique (Tailwind purge les classes dynamiques) |

---

### §Compatibilité Forward — Story 2.6

Story 2.6 mettra à jour les états des nodes en temps réel via `useReactFlow().setNodes()`. Cette histoire pose les fondations :
- `ReactFlowProvider` déjà en place → Story 2.6 peut appeler `useReactFlow()` sans refactoring
- L'`agentStatusStore` (Story 2.5) mettra à jour `data.status` dans les nodes
- La mise à jour se fera via : `setNodes(nds => nds.map(n => n.id === agentId ? { ...n, data: { ...n.data, status } } : n))`
- **Ne PAS implémenter maintenant** — juste s'assurer que l'architecture est prête (ReactFlowProvider, data.status dans les nodes)

---

### Apprentissages des stories précédentes applicables

**Story 1.1 :**
- Structure `frontend/src/` créée et vide — les sous-dossiers (`components/`, `stores/`, `hooks/`, `types/`) existent

**Story 1.2 :**
- Tailwind v4 : config dans `globals.css` — PAS de `tailwind.config.ts`
- Tokens `agent-*` déjà définis et opérationnels
- Composants shadcn installés : `Card`, `Badge`, `Button`, `Select`, `Separator`, `ScrollArea`, `Dialog`, `Sheet`, `Textarea`, `Tooltip`
- Dark mode via classe `dark` sur `<html>` dans `layout.tsx`

**Story 1.3 :**
- `GET /api/workflows` retourne array sans wrapper `data`
- `WorkflowResource` fait la transformation camelCase (seul endroit autorisé)
- `YamlService.loadAll()` charge les steps depuis les YAML — ils sont déjà disponibles dans `$agent['steps']`

**Story 1.4 :**
- Zustand 5.0.12 installé (dans `package.json`)
- `workflowStore` : `selectedWorkflow` contient le workflow complet avec `agents[]`
- `page.tsx` : `'use client'` déjà présent, layout en place
- `next lint` n'existe pas dans Next.js 16.2.2 — utiliser `./node_modules/.bin/eslint src/`
- `Select` shadcn (`@base-ui/react`) : `onValueChange` retourne `string | null`
- Debug log React Flow v12 : vérifier la compatibilité avec React 19.2.4 (peer deps)

---

### §Vérification

```bash
# Backend
cd backend && php artisan serve   # port 8000
# Tester que steps[] apparaît :
curl http://localhost:8000/api/workflows | python3 -m json.tool

# Frontend
cd frontend && npm run dev         # port 3000

# Vérification manuelle :
# 1. Ouvrir http://localhost:3000
# 2. Sélectionner un workflow → le diagramme s'affiche avec les agents
# 3. Chaque agent = une Card avec Badge "idle" (zinc, opacité réduite)
# 4. Étapes listées sous chaque card (icône ○)
# 5. Edges zinc-700 entre les cards
# 6. Changer de workflow → diagramme se reconfigure instantanément
# 7. Interactions désactivées (pas de drag, pan, zoom)

# TypeScript
cd frontend && npx tsc --noEmit

# ESLint
cd frontend && ./node_modules/.bin/eslint src/
```

---

### Project Structure Notes

**Fichiers à créer (frontend) :**
```
frontend/src/
├── components/
│   ├── AgentDiagram.tsx    ← nouveau — React Flow wrapper (FR5)
│   ├── AgentCard.tsx       ← nouveau — custom node type `agentCard` (UX-DR1)
│   ├── StepItem.tsx        ← nouveau — ligne d'étape (UX-DR2)
│   └── DiagramEdge.tsx     ← nouveau — custom edge type `pipelineConnector` (UX-DR3)
```

**Fichiers modifiés (frontend) :**
```
frontend/src/
├── app/
│   └── page.tsx            ← remplace le placeholder Story 1.5
├── types/
│   └── workflow.types.ts   ← ajoute steps: string[] à Agent
└── package.json            ← ajoute @xyflow/react
```

**Fichiers modifiés (backend) :**
```
backend/app/Http/Resources/
└── WorkflowResource.php    ← expose steps[] dans la réponse agents
```

**Note sur les noms de fichiers :** l'architecture doc mentionne `DiagramNode.tsx` mais l'epic AC spécifie `AgentCard` comme nom du composant. Fichier `AgentCard.tsx` adopté (cohérent avec UX spec et AC). `DiagramEdge.tsx` gardé (conforme architecture).

### References

- [Source: docs/planning-artifacts/epics.md#Story-1.5] — user story, AC, UX-DR1/DR2/DR3
- [Source: docs/planning-artifacts/architecture.md#Frontend-Architecture] — React Flow, AgentDiagram.tsx, DiagramNode.tsx, DiagramEdge.tsx
- [Source: docs/planning-artifacts/architecture.md#Structure-Patterns] — organisation src/, naming conventions
- [Source: docs/planning-artifacts/ux-design-specification.md#Composants-Custom] — AgentCard, StepItem, PipelineConnector, états visuels
- [Source: docs/planning-artifacts/implementation-readiness-report-2026-04-03.md] — décision React Flow actée, custom node `agentCard`, custom edge `pipelineConnector`
- [Source: docs/implementation-artifacts/1-4-selecteur-de-workflow-et-rechargement-dynamique.md] — état réel du frontend, Tailwind v4, workflowStore, page.tsx structure

## Dev Agent Record

### Agent Model Used

claude-sonnet-4-6

### Debug Log References

- Test existant `system_prompt_and_steps_are_not_exposed` contradisait l'AC 1.5 (assertait que `steps` ne devait PAS être exposé). Renommé en `steps_are_exposed_and_system_prompt_is_not` avec assertions inversées + nouveau test `agent_without_steps_returns_empty_array`.
- `@xyflow/react` v12.10.2 installé (19 packages ajoutés, 0 vulnérabilités).
- Build Next.js : warning pre-existing `turbopack.root` (lockfile multiple non lié à cette story).

### Completion Notes List

- `backend/app/Http/Resources/WorkflowResource.php` : `steps` ajouté dans le mapping agent (`$agent['steps'] ?? []`)
- `backend/tests/Feature/WorkflowControllerTest.php` : test renommé + nouveau test `agent_without_steps_returns_empty_array` — 14/14 ✅
- `frontend/src/types/workflow.types.ts` : `steps: string[]` ajouté à l'interface `Agent`
- `frontend/package.json` : `@xyflow/react ^12.10.2` ajouté
- `frontend/src/components/StepItem.tsx` : créé — ligne d'étape avec icône et 4 états
- `frontend/src/components/AgentCard.tsx` : créé — custom node React Flow type `agentCard`, base `Card` shadcn, Handle haut/bas invisibles, 4 états visuels
- `frontend/src/components/DiagramEdge.tsx` : créé — custom edge React Flow type `pipelineConnector`, `getStraightPath`, transition CSS 300ms
- `frontend/src/components/AgentDiagram.tsx` : créé — wrapper React Flow avec `ReactFlowProvider`, `nodeTypes`/`edgeTypes` stables hors composant, `fitView`, interactions désactivées, hauteur dynamique
- `frontend/src/app/page.tsx` : import `AgentDiagram` ajouté, placeholder remplacé, `<aside>` et `<footer>` intacts
- TypeScript : 0 erreur. ESLint : 0 warning. Build Next.js : succès. Tests Laravel : 14/14 ✅

### Review Findings (2026-04-04)

- [x] [Review][Patch] AgentCard border/ring cassé — Card utilise `ring-1` pas `border`, `border-inherit` inefficace, états visuels UX-DR1 invisibles — fix: `wrapperStyles` (opacity/shadow) + `cardRingStyles` (ring-*) sur Card [frontend/src/components/AgentCard.tsx]
- [x] [Review][Patch] AgentCard espacement excessif — Card `py-4 gap-4` defaults → 28px au-dessus du nom, 16px entre header et steps — fix: ajouter `py-0 gap-1` à Card [frontend/src/components/AgentCard.tsx:38]
- [x] [Review][Patch] StepItem template string — utiliser `cn()` au lieu de template string pour Tailwind v4 scanning [frontend/src/components/StepItem.tsx:21]
- [x] [Review][Patch] AgentCard key={i} anti-pattern — utiliser `key={step}` [frontend/src/components/AgentCard.tsx:51]
- [x] [Review][Defer] AgentDiagram positions Y ignorent nombre de steps — `index * 220` est une heuristique; `fitView` compense pour Story 1.5 [frontend/src/components/AgentDiagram.tsx:22] — deferred, acceptable pour pipeline statique
- [x] [Review][Defer] AgentDiagram containerHeight statique — calcul par `agents.length * 220` ignore hauteur réelle avec steps variables [frontend/src/components/AgentDiagram.tsx:42] — deferred, à réévaluer Story 2.6
- [x] [Review][Defer] AgentDiagram absence de filtre frontend agent.id vide — backend le filtre déjà (test confirmé), defense-in-depth optionnelle [frontend/src/components/AgentDiagram.tsx:19] — deferred, backend garantit la validité

### Change Log

- 2026-04-04 : Story 1.5 implémentée — diagramme statique React Flow v12 avec `AgentCard`, `StepItem`, `DiagramEdge`, `ReactFlowProvider`

### File List

- backend/app/Http/Resources/WorkflowResource.php (modifié — steps exposé)
- backend/tests/Feature/WorkflowControllerTest.php (modifié — test steps mis à jour + nouveau test)
- frontend/package.json (modifié — @xyflow/react ajouté)
- frontend/package-lock.json (modifié — lockfile mis à jour)
- frontend/src/types/workflow.types.ts (modifié — steps: string[] dans Agent)
- frontend/src/components/StepItem.tsx (nouveau)
- frontend/src/components/AgentCard.tsx (nouveau)
- frontend/src/components/DiagramEdge.tsx (nouveau)
- frontend/src/components/AgentDiagram.tsx (nouveau)
- frontend/src/app/page.tsx (modifié — AgentDiagram intégré)
