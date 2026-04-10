# Story 3.3 : Alerte d'erreur localisée et BubbleBox error

Status: done

## Story

As a développeur,
I want voir l'erreur localisée sur le nœud concerné avec le message détaillé et un bouton Retry,
so that je sais exactement ce qui a planté et je peux agir immédiatement sans quitter la vue.

## Acceptance Criteria

1. **Given** un événement `run.error` reçu via SSE — **When** l'erreur est traitée par `useSSEListener` — **Then** la `AgentCard` de l'agent concerné passe à l'état `error` (border red-500, `animate-pulse`) (FR22, UX-DR1)

2. **Given** un événement `run.error` reçu via SSE — **When** l'erreur est traitée — **Then** une `BubbleBox` variante `error` apparaît inline sous la card de l'agent concerné : message d'erreur + bouton "Relancer cette étape" (ghost sm) (FR27, UX-DR4)

3. **Given** un `run.error` sur l'agent A dans un workflow multi-agents — **When** l'erreur est traitée — **Then** les autres `AgentCard` restent dans leur dernier état connu — aucune perturbation des nœuds sains

4. **Given** un `run.error` — **When** `BubbleBox error` est affichée — **Then** le message d'erreur est humainement lisible (champ `message` du payload SSE `{ message, code }` envoyé par Laravel) (FR27)

5. **Given** un `run.error` reçu — **When** `useSSEListener` traite l'événement — **Then** la `LaunchBar` passe en état `ready` — le bouton "Annuler" disparaît et le bouton "Lancer" réapparaît

## Tasks / Subtasks

- [x] **T1 — `BubbleBox.tsx` : bouton shadcn ghost sm + affichage inconditionnel** (AC 2)
  - [x] Importer `Button` depuis `@/components/ui/button`
  - [x] Remplacer le `<button>` plain par `<Button variant="ghost" size="sm">` avec className adapté aux couleurs error
  - [x] Supprimer la condition `onRetry &&` — le bouton s'affiche toujours quand `variant === 'error'`
  - [x] `onClick={onRetry}` reste inchangé (undefined si non fourni — no-op React)

- [x] **T2 — `AgentCard.tsx` : prop `errorMessage` et logique BubbleBox conditionnelle** (AC 1, 2, 3)
  - [x] Ajouter `errorMessage?: string` à l'interface `AgentCardData`
  - [x] Remplacer le bloc `{data.bubbleMessage && <BubbleBox variant="info" ...>}` par une logique prioritaire :
    - Si `data.status === 'error' && data.errorMessage` → `<BubbleBox variant="error" message={data.errorMessage} />`
    - Sinon si `data.bubbleMessage` → `<BubbleBox variant="info" message={data.bubbleMessage} />`
    - Sinon → rien

- [x] **T3 — `AgentDiagram.tsx` : passer `errorMessage` dans les node data** (AC 2)
  - [x] Dans `useMemo`, ajouter `errorMessage: agents[agent.id]?.errorMessage ?? ''` au champ `data` de chaque nœud

- [x] **T4 — Vérification AC 1, 3, 4, 5** (observation des comportements déjà câblés)
  - [x] Confirmer AC 1 : `useSSEListener` appelle déjà `setAgentStatus(agentId, 'error', ...)` → `AgentCard` passe en error ✓
  - [x] Confirmer AC 3 : seul l'agent concerné voit son status changé ✓
  - [x] Confirmer AC 4 : `parseRunError` extrait `payload.message` déjà lisible ✓
  - [x] Confirmer AC 5 : `setRunError` → `status: 'error'` → `launchBarState = 'ready'` (car `status !== 'running'`) ✓

- [x] **T5 — Test manuel / smoke test**
  - [x] TypeScript compile sans erreur (tsc --noEmit) — zéro erreur de type
  - [x] ESLint sans erreur sur les 3 fichiers modifiés
  - [x] Vérification visuelle : logique conditionnelle BubbleBox correcte dans AgentCard
  - [x] Aucune régression possible — seuls les 3 composants front touchés, aucun backend

### Review Findings (2026-04-10)

- [x] [Review][Decision] Bouton "Relancer cette étape" clickable mais no-op en 3.3 — résolu : `disabled={!onRetry}` ajouté dans BubbleBox.tsx. Bouton grisé tant que le handler 3.4 n'est pas câblé. [BubbleBox.tsx:27]
- [x] [Review][Defer] Silent failure si `selectedWorkflow` null — conteneur 400px sans feedback utilisateur [AgentDiagram.tsx] — deferred, pre-existing
- [x] [Review][Defer] `StepItem` toujours `status="pending"` indépendamment du step actuel [AgentCard.tsx] — deferred, pre-existing
- [x] [Review][Defer] Performances `useMemo` recalcule nodes+edges à chaque update `agents` [AgentDiagram.tsx] — deferred, pre-existing
- [x] [Review][Defer] Badge état sans `role="status"` / `aria-live` — non annoncé aux lecteurs d'écran [AgentCard.tsx] — deferred, pre-existing
- [x] [Review][Defer] `RunErrorEvent.agentId` non validé dans `parseRunError` (type dit `required` mais parser ne vérifie pas) [sseEventParser.ts] — deferred, pre-existing

## Dev Notes

### §ÉTAT ACTUEL — Ne pas réinventer

```
frontend/src/components/BubbleBox.tsx
  → variant: 'info' | 'error' | 'success' — déjà défini
  → onRetry?: () => void — prop déjà présente
  → Bouton actuel : <button> plain avec underline style — À REMPLACER par shadcn Button
  → Condition actuelle : {variant === 'error' && onRetry && (...)} — À MODIFIER : retirer `onRetry &&`

frontend/src/components/AgentCard.tsx
  → AgentCardData : name, engine, steps[], status, bubbleMessage? — MODIFIER : ajouter errorMessage?
  → Bubble actuelle : {data.bubbleMessage && <BubbleBox variant="info" message={data.bubbleMessage} />}
  → La card applique déjà ring-red-500 + animate-pulse pour status=error (wrapperStyles + cardRingStyles)

frontend/src/components/AgentDiagram.tsx
  → node data actuel : { name, engine, steps, status, bubbleMessage } — MODIFIER : ajouter errorMessage
  → Lit agents[agent.id]?.status et agents[agent.id]?.bubbleMessage depuis agentStatusStore

frontend/src/stores/agentStatusStore.ts
  → AgentState : { status, step, bubbleMessage, errorMessage } — errorMessage DÉJÀ présent
  → setAgentStatus() : errorMessage peuplé quand status === 'error' — NE PAS MODIFIER
  → setAgentBubble() : bubbleMessage indépendant de errorMessage — NE PAS MODIFIER

frontend/src/hooks/useSSEListener.ts
  → RUN_ERROR handler : appelle setAgentStatus(agentId, 'error', step, message) + setRunError(message)
  → errorMessage déjà stocké dans agentStatusStore — NE PAS MODIFIER

frontend/src/stores/runStore.ts
  → setRunError(message) → { status: 'error', errorMessage: message }
  → LaunchBar lit status : 'running' → state 'running', sinon → state 'ready' — NE PAS MODIFIER
```

---

### §Modification exacte de `BubbleBox.tsx`

**Changements :**
1. Importer `Button`
2. Remplacer `<button>` plain par `<Button variant="ghost" size="sm">` stylisé
3. Toujours afficher le bouton quand `variant === 'error'` (retirer `onRetry &&`)

```tsx
import { Button } from '@/components/ui/button'

interface BubbleBoxProps {
  variant: 'info' | 'error' | 'success'
  message: string
  onRetry?: () => void
}

const styles: Record<BubbleBoxProps['variant'], string> = {
  info:    'bg-blue-500/10 border-blue-500/30 text-blue-300',
  error:   'bg-red-500/10 border-red-500/30 text-red-300',
  success: 'bg-emerald-500/10 border-emerald-500/30 text-emerald-300',
}

export function BubbleBox({ variant, message, onRetry }: BubbleBoxProps) {
  return (
    <div className={`rounded-md border px-3 py-2 text-xs mt-1.5 ${styles[variant]}`}>
      <p>{message}</p>
      {variant === 'error' && (
        <Button
          variant="ghost"
          size="sm"
          className="mt-1.5 h-6 px-2 text-xs text-red-300 hover:text-red-200 hover:bg-red-500/10"
          onClick={onRetry}
          aria-label="Relancer cette étape"
        >
          Relancer cette étape
        </Button>
      )}
    </div>
  )
}
```

**Pourquoi `onRetry` sans guard :** Le bouton doit être visible en 3.3 (UX-DR4). L'`onClick={onRetry}` quand `onRetry` est `undefined` est un no-op React valide. Story 3.4 passera un handler réel.

---

### §Modification exacte de `AgentCard.tsx`

**Seuls changements :** ajouter `errorMessage?` à l'interface et modifier le rendu BubbleBox.

```tsx
interface AgentCardData {
  name: string
  engine: string
  steps: string[]
  status: 'idle' | 'working' | 'done' | 'error'
  bubbleMessage?: string
  errorMessage?: string   // ← NOUVEAU
}
```

Remplacer le bloc BubbleBox (actuellement `{data.bubbleMessage && ...}`) par :

```tsx
{data.status === 'error' && data.errorMessage ? (
  <BubbleBox variant="error" message={data.errorMessage} />
) : data.bubbleMessage ? (
  <BubbleBox variant="info" message={data.bubbleMessage} />
) : null}
```

**Positionnement :** entre `</Card>` et `<Handle type="source">` — identique à l'emplacement actuel du bloc BubbleBox.

---

### §Modification exacte de `AgentDiagram.tsx`

Dans le `useMemo`, ajouter une seule ligne dans le champ `data` :

```tsx
data: {
  name: agent.id,
  engine: agent.engine,
  steps: agent.steps,
  status: agents[agent.id]?.status ?? 'idle',
  bubbleMessage: agents[agent.id]?.bubbleMessage ?? '',
  errorMessage: agents[agent.id]?.errorMessage ?? '',   // ← NOUVEAU
},
```

---

### §Comportements déjà câblés — ne pas toucher

| AC | Mécanisme existant | Fichier |
|---|---|---|
| AC 1 — AgentCard error state | `useSSEListener` RUN_ERROR → `setAgentStatus(agentId, 'error', ...)` → `AgentCard` ring-red-500 + animate-pulse | useSSEListener.ts, AgentCard.tsx |
| AC 3 — autres cards inchangées | `setAgentStatus` n'affecte que l'agentId du payload | agentStatusStore.ts |
| AC 4 — message lisible | `parseRunError` extrait `data.message` (champ string Laravel) | sseEventParser.ts |
| AC 5 — LaunchBar ready | `setRunError` → `status: 'error'` → `launchBarState` ≠ 'running' → 'ready' | runStore.ts, LaunchBar.tsx |

---

### §Coexistence bubbleMessage / errorMessage

Quand un agent `mandatory` épuise ses retries (story 3.2) :
- `setAgentBubble` a posé `bubbleMessage = "Tentative X/N en cours..."`
- `setAgentStatus(error)` pose `errorMessage = "..."` **mais NE clear pas `bubbleMessage`**

La logique dans AgentCard résout ça correctement par priorité :
`status === 'error' && errorMessage` → error bubble (info bubble ignorée visuellement) ✓

---

### §Bouton "Relancer cette étape" — scope 3.3 vs 3.4

- En 3.3 : bouton visible, `onRetry` non passé → click no-op
- En 3.4 : `AgentCard` recevra un `onRetry` prop propagé depuis `AgentDiagram` → wire le `POST /api/runs/{id}/retry-step`
- **NE PAS** pré-implémenter la logique 3.4 dans cette story

---

### §Edge en état error (non-scope)

Le `DiagramEdge` affiche `inactive` (zinc-700) quand l'agent source est en `error` — ce comportement est délibérément conservé (deferred 2-6). L'alerte est localisée sur la card, pas sur l'edge. Aucune modification de `AgentDiagram.tsx` sur la logique edge.

---

### §Guardrails — erreurs critiques à éviter

| ❌ Interdit | ✅ Correct |
|---|---|
| Modifier `useSSEListener.ts` | Déjà câblé pour RUN_ERROR — ne pas toucher |
| Modifier `agentStatusStore.ts` | `errorMessage` déjà stocké — ne pas toucher |
| Modifier `runStore.ts` | `setRunError` → status 'error' déjà correct — ne pas toucher |
| Modifier `LaunchBar.tsx` | Déjà passe en 'ready' via runStore.status — ne pas toucher |
| Pré-implémenter le retry 3.4 | Story 3.3 = affichage seulement — bouton visible, no-op |
| Utiliser `<button>` plain pour "Relancer" | Utiliser `<Button variant="ghost" size="sm">` de shadcn |
| Conditionner le bouton error sur `onRetry` | Toujours afficher en variant error (UX-DR4 l'exige) |
| Ajouter `clearBubbleMessage` dans `setAgentStatus` | La priorité dans AgentCard résout déjà la coexistence |
| Modifier l'interface du store pour cette story | Aucun changement de store nécessaire |

---

### §Fichiers à créer / modifier

```
frontend/src/components/BubbleBox.tsx     ← MODIFIER (Button shadcn, retirer `onRetry &&`)
frontend/src/components/AgentCard.tsx     ← MODIFIER (+ errorMessage prop, logique conditionnelle)
frontend/src/components/AgentDiagram.tsx  ← MODIFIER (+ errorMessage dans node data)
```

**Ne pas toucher :**
- `useSSEListener.ts` — RUN_ERROR handler déjà correct
- `agentStatusStore.ts` — errorMessage déjà stocké
- `runStore.ts` — status:error déjà géré
- `LaunchBar.tsx` — retour ready déjà fonctionnel
- `sseEventParser.ts` — parseRunError déjà correct
- Tout le backend Laravel

### Project Structure Notes

- `BubbleBox`, `AgentCard`, `AgentDiagram` dans `frontend/src/components/` — convention: PascalCase, `'use client'` si nécessaire (BubbleBox et AgentCard n'ont pas `'use client'` aujourd'hui — vérifier si `Button` l'exige; shadcn Button est un Server Component compatible, pas besoin de `'use client'`)
- `Button` shadcn : `frontend/src/components/ui/button.tsx` — déjà présent, `import { Button } from '@/components/ui/button'`
- Next.js 16.2.2 — lire `node_modules/next/dist/docs/` si incertitude sur un pattern Next.js (cf. `frontend/AGENTS.md`)

### References

- [Source: docs/planning-artifacts/epics.md#Story-3.3] — User story, ACs FR22, FR27, UX-DR1, UX-DR4
- [Source: docs/planning-artifacts/epics.md#Epic-3] — Contexte résilience, FRs couverts
- [Source: frontend/src/components/BubbleBox.tsx] — Composant à modifier : button plain → Button shadcn
- [Source: frontend/src/components/AgentCard.tsx] — Composant à modifier : + errorMessage, logique BubbleBox
- [Source: frontend/src/components/AgentDiagram.tsx] — Composant à modifier : + errorMessage dans node data
- [Source: frontend/src/stores/agentStatusStore.ts] — errorMessage déjà présent dans AgentState
- [Source: frontend/src/hooks/useSSEListener.ts#RUN_ERROR] — Câblage SSE déjà correct
- [Source: frontend/src/stores/runStore.ts#setRunError] — status:'error' → LaunchBar ready déjà géré
- [Source: docs/implementation-artifacts/3-2-retry-automatique-des-etapes-mandatory.md#Dev-Notes] — Contexte coexistence bubbleMessage/errorMessage (retry bubbles vs error final)
- [Source: docs/implementation-artifacts/deferred-work.md#Deferred-2-6] — Edge error state délibérément non modifié (inactive = zinc-700)
- [Source: docs/implementation-artifacts/deferred-work.md#Deferred-2-5] — bubbleMessage non vidé sur transition : résolu par priorité dans AgentCard

## Dev Agent Record

### Agent Model Used

claude-sonnet-4-6

### Debug Log References

- `BubbleBox.tsx` : `'use client'` ajouté car le composant importe `Button` de `@base-ui/react/button` (qui est lui-même client). Sans ce marqueur, risque d'erreur React Server Components si jamais BubbleBox est utilisé hors d'un parent client.
- `Button` shadcn du projet utilise `@base-ui/react/button` (pas Radix) — `onClick` accepte `() => void` par contrat TypeScript (bivariance des paramètres) → pas de cast nécessaire.
- `npm run lint` (next lint) intercepté par rtk proxy → "no such directory: .../lint". Contourné par `eslint` direct sur les fichiers modifiés → 0 erreur.
- Aucune modification de store, hook, ou backend nécessaire — toute la plomberie SSE était déjà câblée pour les ACs 1, 3, 4, 5.

### Completion Notes List

- `BubbleBox.tsx` : `<button>` plain remplacé par `<Button variant="ghost" size="sm">` shadcn ; `'use client'` ajouté ; bouton toujours rendu en `variant="error"` (suppression de `onRetry &&`) — `onClick={onRetry}` est no-op si `undefined` (Story 3.4 câblera le handler)
- `AgentCard.tsx` : `errorMessage?: string` ajouté à `AgentCardData` ; logique BubbleBox conditionnelle : priorité error bubble (status=error + errorMessage) sur info bubble (bubbleMessage)
- `AgentDiagram.tsx` : `errorMessage: agents[agent.id]?.errorMessage ?? ''` ajouté dans les node data du `useMemo`
- TypeScript 0 erreur, ESLint 0 erreur sur les fichiers modifiés
- AC1 ✓ AC2 ✓ AC3 ✓ AC4 ✓ AC5 ✓ — tous satisfaits

### File List

- frontend/src/components/BubbleBox.tsx (modifié)
- frontend/src/components/AgentCard.tsx (modifié)
- frontend/src/components/AgentDiagram.tsx (modifié)
