# Story 1.4 : Sélecteur de workflow et rechargement dynamique

Status: done
Epic: 1
Story: 4
Date: 2026-04-04

## Story

As a développeur,
I want un sélecteur de workflow dans la topbar avec rechargement manuel,
so that je peux changer de configuration YAML sans redémarrer l'application.

## Acceptance Criteria

1. **Given** l'API `/api/workflows` opérationnelle — **When** la page se charge — **Then** le `Select` shadcn dans la topbar liste les workflows disponibles avec leur `name` comme libellé (FR2)
2. **Given** la liste des workflows affichée — **When** je sélectionne un workflow dans le dropdown — **Then** `workflowStore` est mis à jour avec la configuration complète du workflow sélectionné (`name`, `file`, `agents[]` avec `id`, `engine`, `timeout`)
3. **Given** un workflow sélectionné ou non — **When** je clique "Recharger" (bouton outline dans la topbar) — **Then** `GET /api/workflows` est rappelé et le dropdown se met à jour avec les fichiers YAML actuels (FR3)
4. **Given** le rechargement en cours — **When** la requête est en vol — **Then** le `Select` est disabled et le bouton Recharger affiche un spinner (icône Loader2 `animate-spin`)
5. **Given** aucun workflow sélectionné — **When** la zone diagramme est rendue — **Then** un message centré invite à sélectionner un workflow (texte `text-zinc-400 text-sm` dans `flex items-center justify-center`)
6. **Given** le layout — **When** la page est rendue — **Then** la topbar est fixée en haut (`h-14`, fond `zinc-900`), la SPA n'a pas de routing entre pages

## Tasks / Subtasks

- [x] **T1 — Installer Zustand** (prérequis `workflowStore`)
  - [x] `npm install zustand` depuis `frontend/`
  - [x] Vérifier que la dépendance apparaît dans `package.json`

- [x] **T2 — Définir les types TypeScript du workflow** (AC: 2)
  - [x] Créer `frontend/src/types/workflow.types.ts`
  - [x] Définir `Agent { id: string; engine: string; timeout: number }`
  - [x] Définir `Workflow { name: string; file: string; agents: Agent[] }`

- [x] **T3 — Créer `workflowStore`** (AC: 1, 2, 4)
  - [x] Créer `frontend/src/stores/workflowStore.ts`
  - [x] État : `workflows: Workflow[]`, `selectedWorkflow: Workflow | null`, `isLoading: boolean`
  - [x] Setters uniquement — zéro logique métier dans le store
  - [x] Mises à jour immutables via spread (voir §workflowStore)

- [x] **T4 — Créer le hook `useWorkflows`** (AC: 1, 3, 4)
  - [x] Créer `frontend/src/hooks/useWorkflows.ts`
  - [x] Logique de fetch `GET /api/workflows` et mise à jour du store
  - [x] Appel initial au montage + fonction `reload()` exposée
  - [x] Gestion `isLoading` : true avant fetch, false après (succès ou erreur)

- [x] **T5 — Créer le composant `WorkflowSelector`** (AC: 1, 2, 3, 4)
  - [x] Créer `frontend/src/components/WorkflowSelector.tsx`
  - [x] `Select` shadcn : liste les workflows, disabled si `isLoading`
  - [x] Bouton "Recharger" : outline, icône `RefreshCw` (ou `Loader2 animate-spin` si `isLoading`)
  - [x] Sélection → `setSelectedWorkflow(workflow)` dans `workflowStore`
  - [x] `'use client'` en tête de fichier

- [x] **T6 — Intégrer dans `page.tsx`** (AC: 1, 5, 6)
  - [x] Remplacer le placeholder `<span>Sélecteur de workflow (Story 1.4)</span>` par `<WorkflowSelector />`
  - [x] Conditionner l'affichage du message "vide" dans `<main>` si `selectedWorkflow === null`
  - [x] Le reste du layout (`<aside>`, `<footer>`) reste intact — ne pas modifier les placeholders des Stories suivantes

### Review Findings (2026-04-04)

- [x] [Review][Decision] Gestion silencieuse des erreurs de fetch — résolu : champ `error: string | null` ajouté dans `workflowStore`, affiché dans `WorkflowSelector` [frontend/src/stores/workflowStore.ts, frontend/src/hooks/useWorkflows.ts, frontend/src/components/WorkflowSelector.tsx]
- [x] [Review][Patch] Réponses HTTP non-2xx traitées comme succès — `if (!res.ok) throw new Error(...)` ajouté avant `res.json()` [frontend/src/hooks/useWorkflows.ts]
- [x] [Review][Patch] `selectedWorkflow` peut devenir orphelin après un rechargement — réconciliation via `useWorkflowStore.getState()` après `setWorkflows()` [frontend/src/hooks/useWorkflows.ts]
- [x] [Review][Patch] Centrage de l'état vide non effectif — état vide sorti du wrapper `max-w-2xl`, rendu comme `flex-1 flex items-center justify-center` direct de `<main>` [frontend/src/app/page.tsx]
- [x] [Review][Patch] Absence d'`AbortController` dans `useEffect` — `AbortController` ajouté avec cleanup `return () => { controller.abort() }` [frontend/src/hooks/useWorkflows.ts]
- [x] [Review][Defer] Pas de validation runtime de `res.json()` — cast TypeScript uniquement ; acceptable pour un backend localhost contrôlé [frontend/src/hooks/useWorkflows.ts] — deferred, pre-existing
- [x] [Review][Defer] Clés `file` dupliquées dans la réponse API — le backend (YamlService) exclut déjà les YAML invalides, unicité garantie [frontend/src/components/WorkflowSelector.tsx] — deferred, pre-existing
- [x] [Review][Defer] `useCallback` deps sur les setters Zustand stables — inoffensif, les setters ne changent jamais de référence [frontend/src/hooks/useWorkflows.ts] — deferred, pre-existing

---

## Dev Notes

### §CRITIQUE — État réel du frontend (lire avant tout)

**Zustand N'EST PAS installé** — `package.json` ne contient pas `zustand`. **Obligatoire d'exécuter `npm install zustand` avant toute création de store.**

**Tailwind CSS v4 est utilisé** — Il n'existe PAS de `tailwind.config.ts` dans ce projet. La configuration des tokens est dans `frontend/src/app/globals.css` via `@theme inline`. Les tokens agent sont déjà définis :
```css
--color-agent-idle:    #71717a;   /* zinc-500 */
--color-agent-working: #3b82f6;   /* blue-500 */
--color-agent-done:    #10b981;   /* emerald-500 */
--color-agent-error:   #ef4444;   /* red-500 */
```
→ Utiliser `text-agent-idle`, `bg-agent-working`, etc. (tokens déjà opérationnels).

**Shadcn v4 + `@base-ui/react`** — Ce projet utilise `@base-ui/react` (successeur de `@radix-ui`), PAS `@radix-ui`. Les imports internes des composants shadcn sont déjà corrects — ne pas modifier les fichiers `src/components/ui/`.

**Next.js 16.2.2 a des breaking changes** — Lire `node_modules/next/dist/docs/` avant d'écrire du code (avertissement explicite dans `frontend/AGENTS.md`). Les composants client nécessitent `'use client'` en première ligne.

**`src/stores/` est vide** — Créer `workflowStore.ts` directement dans ce dossier (le dossier existe).

**`src/hooks/` est vide** — Créer `useWorkflows.ts` directement (le dossier existe).

**`src/types/` est vide** — Créer `workflow.types.ts` directement.

**`src/components/` ne contient que `ui/`** — Ne pas modifier `ui/`. Créer `WorkflowSelector.tsx` dans `src/components/` directement.

**`src/lib/utils.ts` existe déjà** — contient `cn()` helper pour les classes Tailwind. L'utiliser pour les classes conditionnelles.

**`page.tsx` a déjà le squelette de layout** — La topbar `<header>` a un placeholder explicite :
```tsx
<span className="text-zinc-400 text-sm">Sélecteur de workflow (Story 1.4)</span>
```
→ Remplacer uniquement cette ligne par `<WorkflowSelector />`. Ne pas modifier le reste du layout.

**`layout.tsx`** — Utilise `TooltipProvider` de shadcn. Le projet est en SPA mono-vue, pas de routing.

**Proxy Next.js → Laravel** — `next.config.ts` proxifie `/api/:path*` → `http://localhost:8000/api/:path*`. Les appels fetch dans le hook utilisent `/api/workflows` directement (sans `localhost:8000`).

---

### §workflowStore — Implémentation exacte

```typescript
// frontend/src/stores/workflowStore.ts
import { create } from 'zustand'
import type { Workflow } from '@/types/workflow.types'

interface WorkflowState {
  workflows: Workflow[]
  selectedWorkflow: Workflow | null
  isLoading: boolean
  setWorkflows: (workflows: Workflow[]) => void
  setSelectedWorkflow: (workflow: Workflow | null) => void
  setIsLoading: (isLoading: boolean) => void
}

export const useWorkflowStore = create<WorkflowState>((set) => ({
  workflows: [],
  selectedWorkflow: null,
  isLoading: false,
  setWorkflows: (workflows) => set({ workflows }),
  setSelectedWorkflow: (selectedWorkflow) => set({ selectedWorkflow }),
  setIsLoading: (isLoading) => set({ isLoading }),
}))
```

**Règle absolue :** Le store ne contient que l'état et les setters. Zéro fetch, zéro logique de transformation. Les mises à jour Zustand via `set({})` sont immutables par design — ne pas muter `state.workflows` directement.

---

### §Types TypeScript — Implémentation exacte

```typescript
// frontend/src/types/workflow.types.ts
export interface Agent {
  id: string
  engine: string
  timeout: number
}

export interface Workflow {
  name: string
  file: string
  agents: Agent[]
}
```

Ces types correspondent exactement à la réponse de `GET /api/workflows` :
```json
[
  {
    "name": "Example Workflow",
    "file": "example.yaml",
    "agents": [{ "id": "agent-one", "engine": "claude-code", "timeout": 60 }]
  }
]
```

---

### §useWorkflows — Hook de fetch

```typescript
// frontend/src/hooks/useWorkflows.ts
'use client'

import { useEffect, useCallback } from 'react'
import { useWorkflowStore } from '@/stores/workflowStore'
import type { Workflow } from '@/types/workflow.types'

export function useWorkflows() {
  const { setWorkflows, setIsLoading } = useWorkflowStore()

  const fetchWorkflows = useCallback(async () => {
    setIsLoading(true)
    try {
      const res = await fetch('/api/workflows')
      const data: Workflow[] = await res.json()
      setWorkflows(data)
    } catch {
      // Silencieux — la liste reste inchangée en cas d'erreur réseau
    } finally {
      setIsLoading(false)
    }
  }, [setWorkflows, setIsLoading])

  useEffect(() => {
    fetchWorkflows()
  }, [fetchWorkflows])

  return { reload: fetchWorkflows }
}
```

**Convention :** La logique de transformation est dans le hook ou `lib/` — jamais dans le store.

---

### §WorkflowSelector — Implémentation exacte

```tsx
// frontend/src/components/WorkflowSelector.tsx
'use client'

import { RefreshCw, Loader2 } from 'lucide-react'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { Button } from '@/components/ui/button'
import { useWorkflowStore } from '@/stores/workflowStore'
import { useWorkflows } from '@/hooks/useWorkflows'

export function WorkflowSelector() {
  const { workflows, selectedWorkflow, isLoading, setSelectedWorkflow } = useWorkflowStore()
  const { reload } = useWorkflows()

  const handleSelect = (file: string) => {
    const workflow = workflows.find((w) => w.file === file) ?? null
    setSelectedWorkflow(workflow)
  }

  return (
    <div className="flex items-center gap-2">
      <Select
        value={selectedWorkflow?.file ?? ''}
        onValueChange={handleSelect}
        disabled={isLoading}
      >
        <SelectTrigger className="w-52 bg-zinc-800 border-zinc-700 text-zinc-100">
          <SelectValue placeholder="Sélectionner un workflow…" />
        </SelectTrigger>
        <SelectContent className="bg-zinc-800 border-zinc-700">
          {workflows.map((w) => (
            <SelectItem key={w.file} value={w.file} className="text-zinc-100">
              {w.name}
            </SelectItem>
          ))}
        </SelectContent>
      </Select>

      <Button
        variant="outline"
        size="sm"
        onClick={reload}
        disabled={isLoading}
        aria-label="Recharger la liste des workflows"
        className="border-zinc-700 text-zinc-100 hover:bg-zinc-800"
      >
        {isLoading ? (
          <Loader2 className="h-4 w-4 animate-spin" />
        ) : (
          <RefreshCw className="h-4 w-4" />
        )}
        <span className="ml-1 sr-only sm:not-sr-only">Recharger</span>
      </Button>
    </div>
  )
}
```

**Point clé :** `useWorkflows()` est appelé dans `WorkflowSelector` — le fetch initial se déclenche au montage du composant. Le hook retourne `reload` utilisé par le bouton.

---

### §Intégration page.tsx — Modifications exactes

**Modification 1 — Remplacer le placeholder topbar :**
```tsx
// Avant (ligne ~7) :
<span className="text-zinc-400 text-sm">Sélecteur de workflow (Story 1.4)</span>

// Après :
<WorkflowSelector />
```

**Modification 2 — Message vide dans la zone diagramme :**
```tsx
// Dans <main> (wrapper du diagramme), remplacer :
<p className="text-zinc-400 text-sm">Diagramme agents (Story 1.5)</p>

// Par (conditionnel sur selectedWorkflow) :
{selectedWorkflow ? (
  <p className="text-zinc-400 text-sm">Diagramme agents (Story 1.5)</p>
) : (
  <div className="flex flex-1 items-center justify-center">
    <p className="text-zinc-400 text-sm">Sélectionnez un workflow pour afficher le diagramme</p>
  </div>
)}
```

**Important :** `page.tsx` doit devenir `'use client'` (il utilise l'état du store via `WorkflowSelector`). Ajouter `'use client'` en première ligne.

**Ne pas modifier :** `<aside>` sidebar et `<footer>` LaunchBar — ce sont les placeholders des Stories 2.7b et 2.7a.

---

### §Format réponse API — Rappel

`GET /api/workflows` retourne un tableau à la racine (pas de wrapper `data`) :
```json
[
  {
    "name": "Example Workflow",
    "file": "example.yaml",
    "agents": [
      { "id": "agent-one", "engine": "claude-code", "timeout": 60 }
    ]
  }
]
```
**Aucun cast ou transformation nécessaire côté frontend** — les champs sont déjà en camelCase (transformation faite dans `WorkflowResource` Laravel).

---

### §Structure finale attendue après Story 1.4

```
frontend/src/
├── app/
│   ├── globals.css         ← inchangé
│   ├── layout.tsx          ← inchangé
│   └── page.tsx            ← modifié (WorkflowSelector + état vide)
├── components/
│   ├── ui/                 ← inchangé (shadcn)
│   └── WorkflowSelector.tsx ← nouveau
├── hooks/
│   └── useWorkflows.ts     ← nouveau
├── stores/
│   └── workflowStore.ts    ← nouveau
├── types/
│   └── workflow.types.ts   ← nouveau
└── lib/
    └── utils.ts            ← inchangé
```

---

### §Guardrails — Erreurs à ne pas commettre

| ❌ Interdit | ✅ Correct |
|---|---|
| Utiliser `zustand` sans l'installer | `npm install zustand` en premier |
| Chercher `tailwind.config.ts` | La config Tailwind v4 est dans `globals.css` (`@theme inline`) |
| Importer depuis `@radix-ui/react-*` | Les composants shadcn importent depuis `@base-ui/react` |
| Modifier les fichiers `src/components/ui/` | Utiliser les composants tels quels |
| Mettre de la logique fetch dans le store | Le store ne contient qu'état + setters |
| Nommer `loading` ou `pending` l'état de chargement | Utiliser `isLoading: boolean` |
| Muter directement `state.workflows` | Mises à jour via `set({ workflows: newValue })` |
| Instancier `fetch()` ou `EventSource` directement dans un composant | Utiliser un hook |
| Modifier les placeholders des Stories 2.7a et 2.7b | Ne pas toucher `<footer>` et `<aside>` |
| Créer un routing Next.js (pages multiples) | SPA mono-vue, tout dans `page.tsx` |
| Appeler `http://localhost:8000/api/workflows` directement | Utiliser `/api/workflows` (proxifié par Next.js) |
| `import { useWorkflowStore } from 'zustand'` | `import { useWorkflowStore } from '@/stores/workflowStore'` |

---

### §Accessibilité (UX-DR12)

- Le bouton "Recharger" doit avoir `aria-label="Recharger la liste des workflows"` (bouton sans texte visible en mobile)
- Le `Select` shadcn gère nativement le focus et la navigation clavier — ne pas surcharger
- `Tab order` dans la topbar : Select → Bouton Recharger

---

### §Vérification

```bash
# Démarrer les deux serveurs
cd backend && php artisan serve   # port 8000
cd frontend && npm run dev        # port 3000

# Vérification manuelle :
# 1. Ouvrir http://localhost:3000
# 2. Le Select affiche les workflows du dossier workflows/
# 3. Sélectionner un workflow → le message "Sélectionnez un workflow" disparaît
# 4. Cliquer Recharger → spinner visible, puis liste mise à jour
# 5. Aucun workflow sélectionné → message centré visible dans la zone diagramme
```

---

### Apprentissages des stories précédentes applicables

**Story 1.1 :**
- `DriverInterface::cancel(string $jobId)` — PAS `kill(int $pid)` (ne pas confondre si Epic 2 est mentionné)
- La structure de dossiers `src/` est créée et vide — les sous-dossiers existent

**Story 1.2 :**
- Tokens Tailwind définis dans `globals.css` — PAS dans `tailwind.config.ts` (fichier inexistant en Tailwind v4)
- Composants shadcn installés : `Card`, `Badge`, `Button`, `Select`, `Separator`, `ScrollArea`, `Dialog`, `Sheet`, `Textarea`, `Tooltip`
- Dark mode appliqué via la classe `dark` sur `<html>` dans `layout.tsx`
- Le layout 2 colonnes est déjà en place dans `page.tsx`

**Story 1.3 :**
- `GET /api/workflows` opérationnel, retourne array JSON sans wrapper `data`
- `symfony/yaml 7.x` installé dans le backend
- `YamlService.loadAll()` exclut silencieusement les YAML invalides
- `WorkflowResource` transforme en camelCase — les champs frontend sont `name`, `file`, `agents[].id`, `agents[].engine`, `agents[].timeout`
- `config('xu-workflow.default_timeout')` = 120s pour les agents sans `timeout` explicite

---

### Références

- [Source: docs/planning-artifacts/epics.md#Story-1.4] — AC et user story
- [Source: docs/planning-artifacts/architecture.md#Frontend-Architecture] — Zustand stores, hooks, naming conventions
- [Source: docs/planning-artifacts/architecture.md#Implementation-Patterns] — loading states, Zustand immuable
- [Source: docs/planning-artifacts/epics.md#Additional-Requirements] — règles Zustand, proxy Next.js
- [Source: docs/implementation-artifacts/1-2-design-system-tokens-couleur-dark-mode-et-layout-principal.md] — Tailwind v4, tokens, composants shadcn disponibles
- [Source: docs/implementation-artifacts/1-3-api-laravel-chargement-validation-et-exposition-des-workflows-yaml.md] — format réponse API, champs exposés

## Dev Agent Record

### Agent Model Used

claude-sonnet-4-6

### Debug Log References

- `onValueChange` du composant `Select` (@base-ui/react) retourne `string | null`, pas `string` — handleSelect mis à jour en conséquence
- `next lint` supprimé de Next.js 16.2.2 — ESLint lancé directement via `./node_modules/.bin/eslint src/`

### Completion Notes List

- `npm install zustand` → zustand 5.0.12 installé
- `frontend/src/types/workflow.types.ts` : interfaces `Agent` et `Workflow` créées
- `frontend/src/stores/workflowStore.ts` : store Zustand avec état `workflows`, `selectedWorkflow`, `isLoading` + setters uniquement
- `frontend/src/hooks/useWorkflows.ts` : hook de fetch avec `useCallback` + `useEffect` au montage, expose `reload()`
- `frontend/src/components/WorkflowSelector.tsx` : Select shadcn + bouton Recharger avec Loader2/RefreshCw selon `isLoading`
- `frontend/src/app/page.tsx` : `'use client'` ajouté, `WorkflowSelector` intégré, message vide conditionnel affiché si `selectedWorkflow === null`
- TypeScript : 0 erreur. ESLint : 0 warning. Build Next.js : succès. Tests Laravel : 13/13 ✅

### File List

- frontend/package.json (modifié — zustand ajouté)
- frontend/package-lock.json (modifié — lockfile mis à jour)
- frontend/src/types/workflow.types.ts (nouveau)
- frontend/src/stores/workflowStore.ts (nouveau)
- frontend/src/hooks/useWorkflows.ts (nouveau)
- frontend/src/components/WorkflowSelector.tsx (nouveau)
- frontend/src/app/page.tsx (modifié — WorkflowSelector + état vide)

### Change Log

- 2026-04-04 : Création story 1.4 — sélecteur workflow + rechargement dynamique
- 2026-04-04 : Implémentation complète — Zustand store, hook useWorkflows, composant WorkflowSelector, intégration page.tsx
- 2026-04-04 : Code review — 5 patches appliqués : res.ok check, AbortController, réconciliation selectedWorkflow, error state store, centrage état vide
