# Story 2.7b : Bulles SSE, sidebar de log et modal de fin de run

Status: done

## Story

As a développeur,
I want voir les bulles SSE sur l'agent actif, le log de session en temps réel dans la sidebar, et un récapitulatif à la fin du run,
so that j'ai une visibilité complète sur la progression et les résultats sans lire les logs bruts.

## Acceptance Criteria

1. **Given** un run actif — **When** un événement `agent.bubble` est reçu via SSE — **Then** une `BubbleBox` variante `info` apparaît inline sous la `AgentCard` active (dans le custom node React Flow) avec le message de l'étape (FR20, UX-DR4) — **And** si une `BubbleBox` existait déjà sur cette card, elle est remplacée par la nouvelle

2. **Given** un run actif — **When** le stream SSE est ouvert — **Then** la sidebar s'enrichit en append-only via polling `GET /api/runs/{id}/log` (toutes les 2s) avec le contenu de `session.md` (FR21) — **And** la `ScrollArea` de la sidebar défile automatiquement vers le bas à chaque nouvel ajout

3. **Given** un run actif — **When** un événement `run.completed` est reçu — **Then** le `RunSummaryModal` (`Dialog` shadcn) s'ouvre automatiquement avec : nombre d'agents exécutés, durée totale (ms → format lisible), statut global `completed`, lien vers le dossier run (FR23, UX-DR5) — **And** la modal est fermable via `Escape` ou le bouton × — **And** après fermeture, le diagramme reste dans son état final `done`

4. **And** tous les éléments interactifs (bouton ×, lien dossier) sont accessibles au clavier avec focus visible (UX-DR12)

## Tasks / Subtasks

- [x] **T1 — Créer `BubbleBox.tsx`** (AC 1)
  - [x] Créer `frontend/src/components/BubbleBox.tsx`
  - [x] Props : `variant: 'info' | 'error' | 'success'`, `message: string`, `onRetry?: () => void`
  - [x] Variante `info` : fond `blue-500/10`, bordure `blue-500/30`, texte `blue-300`
  - [x] Variante `error` : fond `red-500/10`, bordure `red-500/30`, texte `red-300` + bouton "Relancer" `ghost sm` conditionnel si `onRetry` fourni
  - [x] Variante `success` : fond `emerald-500/10`, bordure `emerald-500/30`, texte `emerald-300`
  - [x] Pas de dépendance shadcn — div Tailwind pur

- [x] **T2 — Modifier `AgentCard.tsx` pour intégrer BubbleBox** (AC 1)
  - [x] Ajouter `bubbleMessage?: string` à l'interface `AgentCardData`
  - [x] Render `<BubbleBox variant="info" message={data.bubbleMessage} />` sous la `<Card>`, dans le wrapper div, si `data.bubbleMessage` est non vide
  - [x] La BubbleBox est en dehors de la `<Card>` (après `</Card>`, avant le Handle source) pour apparaître visuellement en dessous

- [x] **T3 — Modifier `AgentDiagram.tsx` pour passer `bubbleMessage`** (AC 1)
  - [x] Ajouter `bubbleMessage: agents[agent.id]?.bubbleMessage ?? ''` dans les données de chaque node
  - [x] Augmenter le y-spacing de `220` à `280` pour accommoder la BubbleBox (éviter overlap inter-nodes)

- [x] **T4 — Créer `RunSidebar.tsx`** (AC 2)
  - [x] Créer `frontend/src/components/RunSidebar.tsx` avec `'use client'`
  - [x] Lire `runId` depuis `useRunStore()`, `status` depuis `useRunStore()`
  - [x] `useState<string>('')` pour `logContent`
  - [x] `useEffect` avec `setInterval(2000)` — poll `GET /api/runs/{runId}/log` tant que `runId !== null && status === 'running'`
  - [x] Stopper le polling quand `status` change à `'completed'` ou `'error'` (nettoyage de l'interval dans le cleanup du useEffect)
  - [x] Effectuer une dernière requête `GET /api/runs/{runId}/log` quand `status` passe à `'completed'` pour capturer la log finale
  - [x] Utiliser `ScrollArea` shadcn (`@/components/ui/scroll-area`) pour le rendu
  - [x] `<pre>` avec `text-xs text-zinc-300 whitespace-pre-wrap` pour afficher le contenu Markdown brut
  - [x] Auto-scroll : `useRef` sur un div sentinelle en fin de contenu + `scrollIntoView({ behavior: 'smooth' })` à chaque mise à jour de `logContent`
  - [x] État vide : afficher `<p className="text-zinc-500 text-xs">En attente du démarrage du run...</p>`

- [x] **T5 — Modifier `page.tsx` pour intégrer RunSidebar** (AC 2)
  - [x] Remplacer le placeholder `<p className="text-zinc-400 text-sm">Sidebar log (Story 2.7b)</p>` par `<RunSidebar />`
  - [x] `RunSidebar` prend toute la hauteur disponible via `flex-1 overflow-hidden`

- [x] **T6 — Créer `RunSummaryModal.tsx`** (AC 3, 4)
  - [x] Créer `frontend/src/components/RunSummaryModal.tsx` avec `'use client'`
  - [x] Utiliser `Dialog`, `DialogContent`, `DialogHeader`, `DialogTitle` depuis `@/components/ui/dialog`
  - [x] Ouvrir automatiquement quand `runStore.status === 'completed'` (dériver `open` sans useEffect : `const open = status === 'completed'`)
  - [x] Données affichées :
    - Nombre d'agents : `Object.keys(agents).length` depuis `useAgentStatusStore()`
    - Durée : `runStore.duration` (ms) → afficher en secondes (`(duration / 1000).toFixed(1)s`)
    - Statut global : "Terminé avec succès" (badge `emerald`)
    - Lien dossier run : `runStore.runFolder` — afficher comme `<code>` et bouton "Copier" si non null
  - [x] Fermeture : `onOpenChange` appelé uniquement si user ferme — **NE PAS** appeler `resetRun()` à la fermeture de la modal (le diagramme doit garder son état final)
  - [x] La modal ne se ferme pas toute seule — l'utilisateur doit explicitement fermer
  - [x] Accessibilité : `DialogTitle` explicite, bouton × via shadcn (inclus par défaut dans `DialogContent`)

- [x] **T7 — Modifier `page.tsx` pour intégrer RunSummaryModal** (AC 3)
  - [x] Importer et rendre `<RunSummaryModal />` dans `page.tsx` (niveau racine de la div principale)

- [x] **T8 — Backend : `GET /api/runs/{id}/log`** (AC 2)
  - [x] Ajouter dans `backend/routes/api.php` : `Route::get('/runs/{id}/log', [RunController::class, 'log']);`
  - [x] Ajouter `use Illuminate\Support\Facades\File;` dans `RunController`
  - [x] Implémenter `RunController::log(string $id)` : lecture via `run:{id}:path` (clé dédiée persistante, non supprimée dans finally)
  - [x] `RunService` stocke `runPath` dans `run:{$runId}:path` après `initializeRun` (clé TTL 7200s, survit au `finally`)

- [x] **T9 — Vérification manuelle** (AC 1–4)
  - [x] `npx tsc --noEmit` : 0 erreur
  - [x] `eslint src/` : 0 erreur, 0 warning

### Review Findings (2026-04-09)

- [x] [Review][Decision] runFolder : `<code>` vs lien navigable — décision : bouton "Copier le chemin" (`navigator.clipboard.writeText`) avec feedback "Copié ✓" — résolu [RunSummaryModal.tsx]
- [x] [Review][Patch] RunSidebar : l'interval est recréé même quand `status` est `completed`/`error` — résolu : guard `if (status !== 'running') return` avant `setInterval` [RunSidebar.tsx:useEffect]
- [x] [Review][Patch] RunSummaryModal : `setDismissed(false)` appelé dans le corps du render — résolu : pattern getDerivedStateFromProps avec `prevStatus` (cohérent avec LaunchBar 2.7a) [RunSummaryModal.tsx]
- [x] [Review][Defer] RunController::log() : aucune validation que le `$id` correspond à un run connu — retourne `{'content': ''}` pour tout ID inconnu, pas de fuite de données mais pas de 404 explicite [RunController.php:log()] — deferred, comportement safe, amélioration future
- [x] [Review][Defer] RunSidebar : pas d'AbortController pour annuler les fetch en vol lors d'un changement de runId [RunSidebar.tsx:fetchLog] — deferred, impact pratique faible sur SPA mono-vue
- [x] [Review][Defer] BubbleBox : bouton "Relancer" (variante error) sans `focus-visible:ring` [BubbleBox.tsx:button] — deferred, variante error hors scope 2.7b (Epic 3)
- [x] [Review][Defer] useSSEListener : gestion EventSource en reconnexion automatique — risque théorique de listeners sur instance morte [useSSEListener.ts:onerror] — deferred, pre-existing

## Dev Notes

### §Fichiers à créer / modifier

```
frontend/src/components/BubbleBox.tsx         ← CRÉER
frontend/src/components/RunSidebar.tsx        ← CRÉER
frontend/src/components/RunSummaryModal.tsx   ← CRÉER
frontend/src/components/AgentCard.tsx         ← MODIFIER (ajouter bubbleMessage + BubbleBox)
frontend/src/components/AgentDiagram.tsx      ← MODIFIER (passer bubbleMessage, spacing 280)
frontend/src/app/page.tsx                     ← MODIFIER (RunSidebar + RunSummaryModal)
backend/routes/api.php                        ← MODIFIER (ajouter GET /runs/{id}/log)
backend/app/Http/Controllers/RunController.php ← MODIFIER (méthode log())
backend/app/Services/RunService.php           ← MODIFIER (stocker runPath dans cache)
```

---

### §BubbleBox — structure exacte

```tsx
// frontend/src/components/BubbleBox.tsx
interface BubbleBoxProps {
  variant: 'info' | 'error' | 'success'
  message: string
  onRetry?: () => void
}

const styles = {
  info:    'bg-blue-500/10 border-blue-500/30 text-blue-300',
  error:   'bg-red-500/10 border-red-500/30 text-red-300',
  success: 'bg-emerald-500/10 border-emerald-500/30 text-emerald-300',
}

export function BubbleBox({ variant, message, onRetry }: BubbleBoxProps) {
  return (
    <div className={`rounded-md border px-3 py-2 text-xs mt-1.5 ${styles[variant]}`}>
      <p>{message}</p>
      {variant === 'error' && onRetry && (
        <button
          onClick={onRetry}
          className="mt-1 text-xs underline underline-offset-2 opacity-80 hover:opacity-100"
        >
          Relancer cette étape
        </button>
      )}
    </div>
  )
}
```

---

### §AgentCard — modification minimale

Ajouter `bubbleMessage?: string` à `AgentCardData` et rendre la BubbleBox **après** la Card, **avant** le Handle source :

```tsx
interface AgentCardData {
  name: string
  engine: string
  steps: string[]
  status: 'idle' | 'working' | 'done' | 'error'
  bubbleMessage?: string   // ← AJOUTER
}

// Dans le JSX, après </Card> et avant le Handle source :
{data.bubbleMessage && (
  <BubbleBox variant="info" message={data.bubbleMessage} />
)}
```

**Important :** La BubbleBox est rendue dans le nœud React Flow, ce qui augmente sa hauteur. Le y-spacing dans `AgentDiagram` doit passer de `220` à `280`.

---

### §AgentDiagram — modification minimale

```tsx
// Changer la position Y :
position: { x: 0, y: index * 280 },  // était 220

// Ajouter bubbleMessage dans node.data :
data: {
  name: agent.id,
  engine: agent.engine,
  steps: agent.steps,
  status: agents[agent.id]?.status ?? 'idle',
  bubbleMessage: agents[agent.id]?.bubbleMessage ?? '',  // ← AJOUTER
},
```

Le `containerHeight` utilise déjà `selectedWorkflow.agents.length * 220 + 80` — mettre à jour :
```tsx
const containerHeight = selectedWorkflow
  ? Math.max(400, selectedWorkflow.agents.length * 280 + 80)
  : 400
```

---

### §RunSidebar — polling pattern

```tsx
'use client'
import { useEffect, useRef, useState } from 'react'
import { ScrollArea } from '@/components/ui/scroll-area'
import { useRunStore } from '@/stores/runStore'

export function RunSidebar() {
  const { runId, status } = useRunStore()
  const [logContent, setLogContent] = useState('')
  const bottomRef = useRef<HTMLDivElement>(null)

  useEffect(() => {
    if (!runId) return

    const fetchLog = async () => {
      try {
        const res = await fetch(`/api/runs/${runId}/log`)
        if (!res.ok) return
        const { content } = await res.json()
        setLogContent(content ?? '')
      } catch { /* ignorer erreurs réseau transitoires */ }
    }

    fetchLog() // fetch immédiat au démarrage
    const interval = setInterval(fetchLog, 2000)

    return () => clearInterval(interval)
  }, [runId, status])  // re-exécuter si status change (pour fetch final sur completed)

  // Auto-scroll
  useEffect(() => {
    bottomRef.current?.scrollIntoView({ behavior: 'smooth' })
  }, [logContent])

  return (
    <ScrollArea className="flex-1 p-3">
      {logContent ? (
        <pre className="text-xs text-zinc-300 whitespace-pre-wrap font-mono leading-relaxed">
          {logContent}
        </pre>
      ) : (
        <p className="text-zinc-500 text-xs">En attente du démarrage du run...</p>
      )}
      <div ref={bottomRef} />
    </ScrollArea>
  )
}
```

**Attention :** Le polling s'arrête automatiquement via le cleanup `clearInterval` quand le composant se démonte ou que `runId`/`status` change. Ne pas utiliser `useInterval` custom.

---

### §RunSummaryModal — ouverture automatique sans useEffect

La modal s'ouvre en dérivant `open` depuis le store — aucun `useState` pour `open` :

```tsx
'use client'
import { useState } from 'react'
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { useRunStore } from '@/stores/runStore'
import { useAgentStatusStore } from '@/stores/agentStatusStore'

export function RunSummaryModal() {
  const { status, duration, runFolder } = useRunStore()
  const { agents } = useAgentStatusStore()
  const [dismissed, setDismissed] = useState(false)

  // Auto-reset dismissed quand un nouveau run commence
  if (status === 'running' && dismissed) setDismissed(false)

  const open = status === 'completed' && !dismissed
  const agentCount = Object.keys(agents).length
  const durationDisplay = duration ? `${(duration / 1000).toFixed(1)}s` : '—'

  return (
    <Dialog open={open} onOpenChange={(isOpen) => { if (!isOpen) setDismissed(true) }}>
      <DialogContent className="bg-zinc-900 border-zinc-700 text-zinc-100 max-w-md">
        <DialogHeader>
          <DialogTitle className="text-zinc-100">Run terminé</DialogTitle>
        </DialogHeader>
        <div className="flex flex-col gap-3 py-2">
          <div className="flex justify-between text-sm">
            <span className="text-zinc-400">Agents exécutés</span>
            <span className="font-medium">{agentCount}</span>
          </div>
          <div className="flex justify-between text-sm">
            <span className="text-zinc-400">Durée</span>
            <span className="font-medium">{durationDisplay}</span>
          </div>
          <div className="flex justify-between text-sm">
            <span className="text-zinc-400">Statut</span>
            <span className="text-emerald-400 font-medium">Terminé avec succès</span>
          </div>
          {runFolder && (
            <div className="flex flex-col gap-1 mt-1">
              <span className="text-zinc-400 text-xs">Dossier run</span>
              <code className="text-xs text-zinc-300 bg-zinc-800 rounded px-2 py-1 break-all">
                {runFolder}
              </code>
            </div>
          )}
        </div>
      </DialogContent>
    </Dialog>
  )
}
```

**Point clé :** Le `dismissed` state est local à la modal. Le `resetRun()` n'est pas appelé ici — le diagramme garde son état final jusqu'au prochain `handleLancer` (qui appelle `resetAgents()`).

---

### §Backend — RunService : stocker runPath

Dans `backend/app/Services/RunService.php`, après l'appel à `initializeRun` (ligne 34), mettre à jour le cache entry `run:{$runId}` pour inclure `runPath` :

```php
// Ligne actuelle (~28) :
cache()->put("run:{$runId}", ['status' => 'running', 'startedAt' => $createdAt], 3600);

// Ligne 34 :
$runPath = $this->artifactService->initializeRun($runId, $workflowFile, $brief);

// AJOUTER APRÈS ligne 34 :
cache()->put("run:{$runId}", [
    'status'    => 'running',
    'startedAt' => $createdAt,
    'runPath'   => $runPath,
], 3600);
```

---

### §Backend — RunController::log()

```php
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\File;

public function log(string $id): JsonResponse
{
    $runData = cache()->get("run:{$id}");
    $runPath = $runData['runPath'] ?? null;

    if (! $runPath) {
        return response()->json(['content' => '']);
    }

    $sessionPath = $runPath . '/session.md';

    if (! File::exists($sessionPath)) {
        return response()->json(['content' => '']);
    }

    return response()->json(['content' => File::get($sessionPath)]);
}
```

**Route à ajouter dans `api.php` :**
```php
Route::get('/runs/{id}/log', [RunController::class, 'log']);
```

---

### §page.tsx — modifications

```tsx
// AJOUTER les imports :
import { RunSidebar } from '@/components/RunSidebar'
import { RunSummaryModal } from '@/components/RunSummaryModal'

// REMPLACER le contenu de <aside> :
<aside className="hidden lg:flex w-80 shrink-0 border-l border-zinc-700 bg-zinc-900 flex-col overflow-hidden">
  <RunSidebar />   {/* Remplace le placeholder */}
</aside>

// AJOUTER avant la fermeture du div racine (après <LaunchBar />) :
<RunSummaryModal />
```

---

### §Composants shadcn requis

Vérifier que ces composants shadcn sont déjà installés (installés en 1.2) :
- `Dialog` → `@/components/ui/dialog`
- `ScrollArea` → `@/components/ui/scroll-area`
- `Badge` → `@/components/ui/badge` (déjà dans AgentCard)

Si `scroll-area` n'est pas encore installé : `npx shadcn@latest add scroll-area`

---

### §Guardrails — Erreurs critiques à éviter

| ❌ Interdit | ✅ Correct |
|---|---|
| `useState(false)` pour `open` dans RunSummaryModal, puis `useEffect` pour le synchroniser | Dériver `open` depuis `status === 'completed' && !dismissed` directement |
| Appeler `resetRun()` ou `resetAgents()` à la fermeture de la modal | Ne jamais reset dans la modal — reset seulement au prochain `handleLancer` |
| Polling dans la sidebar même quand `runId === null` | Guard `if (!runId) return` en tête du useEffect |
| `fetch('/api/runs/{runId}/log')` avec template literal sans null guard | Vérifier `runId !== null` avant tout fetch |
| Passer `bubbleMessage` comme chaîne vide `''` et render une BubbleBox vide | Conditionner le render : `{data.bubbleMessage && <BubbleBox ... />}` |
| Oublier de mettre à jour `containerHeight` dans AgentDiagram après le changement de spacing | Changer `220` en `280` aux deux endroits : `position.y` ET `containerHeight` |
| Créer un hook custom `usePolling` | Utiliser directement `useEffect` + `setInterval` + cleanup |
| Import direct `@xyflow/react` dans BubbleBox | BubbleBox est un composant React pur, sans dépendance React Flow |
| Appeler `new EventSource()` dans un nouveau composant | Le SSE est géré exclusivement dans `useSSEListener` (déjà branché dans LaunchBar) |
| Ajouter un second `useSSEListener` dans RunSidebar ou RunSummaryModal | Un seul `useSSEListener` dans `LaunchBar` alimente tous les stores — ne pas dupliquer |

---

### §Flow de données complet

```
SSE event: agent.bubble
  → useSSEListener (dans LaunchBar)
  → agentStatusStore.setAgentBubble(agentId, message)
  → AgentDiagram re-render (agents[id].bubbleMessage change)
  → AgentCard node data mis à jour (bubbleMessage dans data)
  → BubbleBox rendue sous la card correspondante

SSE event: run.completed
  → useSSEListener
  → runStore.setRunCompleted(duration, runFolder)
  → RunSummaryModal: status === 'completed' → open = true → Dialog visible
  → RunSidebar: useEffect déclenché (status change) → fetch final du log
  → LaunchBar: status dérivé → état 'ready' (texte "Lancer")

GET /api/runs/{id}/log (polling sidebar)
  → RunController::log()
  → cache("run:{id}")['runPath'] → File::get(runPath/session.md)
  → { content: "# Run: ...\n..." }
  → RunSidebar setState + auto-scroll
```

---

### §Deferred depuis story 2.7a (scope 2.7b)

- **Réinitialisation agents post-run** : Les agents NE sont PAS resetés sur `run.completed` dans cette story — le diagramme garde son état final pour visibilité et pour que la RunSummaryModal puisse lire le nombre d'agents. `resetAgents()` reste appelé uniquement au prochain `handleLancer` (pattern conservé depuis 2.7a).
- **WorkflowSelector désactivé pendant un run** : toujours deferred (pre-existing, Epic 3+).

---

### §Scope délimité — Ce qui N'appartient PAS à cette story

- **BubbleBox variante `error` + bouton Retry fonctionnel** — Story 3.3
- **RunSummaryModal pour événement `run.error`** — Story 3.3 (la modal ne s'ouvre que sur `completed`)
- **Retry depuis checkpoint** — Epic 3
- **Liste des runs passés** — Epic 4

---

### Project Structure Notes

- Tous les nouveaux composants dans `frontend/src/components/` — PascalCase
- Alias `@/` = `frontend/src/`
- Les imports shadcn : `@/components/ui/dialog`, `@/components/ui/scroll-area`
- Backend PHP : PSR-4 autoloading, namespace `App\Http\Controllers`
- Lire `frontend/AGENTS.md` → `frontend/CLAUDE.md` avant toute hypothèse sur les APIs Next.js

### References

- [Source: docs/planning-artifacts/epics.md#Story-2.7b] — User story, ACs complets (FR20, FR21, FR23, UX-DR4, UX-DR5, UX-DR12)
- [Source: docs/planning-artifacts/architecture.md#Frontend-Architecture] — Structure composants, RunSidebar.tsx, BubbleNotification.tsx, RunCompletionModal.tsx
- [Source: docs/planning-artifacts/architecture.md#API-Patterns] — GET /api/runs/{id}/stream, liste endpoints
- [Source: docs/planning-artifacts/ux-design-specification.md#UX-DR4] — BubbleBox 3 variantes
- [Source: docs/planning-artifacts/ux-design-specification.md#UX-DR5] — RunSummaryModal specs
- [Source: frontend/src/stores/agentStatusStore.ts] — setAgentBubble, bubbleMessage dans AgentState
- [Source: frontend/src/stores/runStore.ts] — setRunCompleted(duration, runFolder), status
- [Source: frontend/src/hooks/useSSEListener.ts] — gestion agent.bubble → setAgentBubble, run.completed → setRunCompleted
- [Source: frontend/src/components/AgentCard.tsx] — interface AgentCardData, structure node React Flow
- [Source: frontend/src/components/AgentDiagram.tsx] — node data construction, y-spacing 220
- [Source: frontend/src/app/page.tsx] — placeholder sidebar à remplacer (ligne ~37)
- [Source: backend/app/Services/RunService.php#L28-34] — cache entry run:{id}, initializeRun → runPath
- [Source: backend/app/Services/ArtifactService.php#initializeRun] — retourne $runPath, crée session.md
- [Source: backend/routes/api.php] — routes existantes, ajouter GET /runs/{id}/log
- [Source: docs/implementation-artifacts/2-7a-launchbar-lancement-et-annulation-d-un-run.md#Review-Findings] — resetAgents() scope, deferred items

## Dev Agent Record

### Agent Model Used

claude-sonnet-4-6

### Debug Log References

- Décision : clé cache `run:{id}:path` séparée plutôt que modification de `run:{id}` — le `finally` de RunService supprime `run:{id}`, donc une clé dédiée avec TTL 7200s garantit que le log reste accessible après la fin du run.
- `RunSummaryModal` : `open` dérivé de `status === 'completed' && !dismissed` sans `useEffect` — pattern getDerivedStateFromProps, cohérent avec LaunchBar (2.7a).
- `RunSidebar` polling déclenché sur `[runId, status]` dans le useEffect — quand `status` passe à `'completed'`, le useEffect se ré-exécute et fait un dernier fetch pour capturer la log finale.

### Completion Notes List

- `BubbleBox.tsx` créé : 3 variantes (info/error/success), Tailwind pur, bouton Retry conditionnel pour variante error
- `AgentCard.tsx` modifié : `bubbleMessage?` ajouté à `AgentCardData`, BubbleBox rendue après `</Card>` si message non vide
- `AgentDiagram.tsx` modifié : y-spacing 220→280, `bubbleMessage` passé dans node data
- `RunSidebar.tsx` créé : polling 2s via `setInterval`, `ScrollArea` shadcn, auto-scroll sur sentinelle, dernier fetch sur changement de status
- `page.tsx` modifié : placeholder sidebar remplacé par `<RunSidebar />`, `<RunSummaryModal />` ajouté en fin de div racine
- `RunSummaryModal.tsx` créé : `Dialog` shadcn, ouverture auto sur `status === 'completed'`, `dismissed` state local, agentCount depuis agentStatusStore, durée formatée en secondes
- Backend `GET /api/runs/{id}/log` : route ajoutée, `RunController::log()` lit `run:{id}:path` depuis cache, retourne contenu de `session.md`
- `RunService.php` modifié : `cache()->put("run:{$runId}:path", $runPath, 7200)` après `initializeRun`
- 0 erreur TypeScript, 0 erreur ESLint

### File List

- frontend/src/components/BubbleBox.tsx (créé)
- frontend/src/components/RunSidebar.tsx (créé)
- frontend/src/components/RunSummaryModal.tsx (créé)
- frontend/src/components/AgentCard.tsx (modifié)
- frontend/src/components/AgentDiagram.tsx (modifié)
- frontend/src/app/page.tsx (modifié)
- backend/routes/api.php (modifié)
- backend/app/Http/Controllers/RunController.php (modifié)
- backend/app/Services/RunService.php (modifié)
