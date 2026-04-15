---
title: 'SSE Disable Auto-Reconnect — erreur sur agent + bouton Retry'
type: 'bugfix'
created: '2026-04-15'
status: 'done'
baseline_commit: '6e8692a83af159a2fa96124a8e35e13ab4c125e0'
context: []
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** Quand le stream SSE se coupe pendant un run actif, l'`EventSource` se reconnecte automatiquement en boucle (comportement natif navigateur + `retry: 3000` backend) sans jamais afficher d'erreur à l'utilisateur ni proposer de retry manuel.

**Approach:** Fermer l'EventSource dans `onerror` (empêche toute reconnexion auto), détecter l'agent en cours d'exécution, le passer en `error` avec un message, et laisser l'infrastructure retry existante (AgentCard BubbleBox + `handleRetry`) prendre le relais. Supprimer la branche "run actif" du replay backend qui n'a plus de raison d'être.

## Boundaries & Constraints

**Always:**
- L'infra retry existante ne change pas : `AgentCard::handleRetry` → `POST /retry-step` → `setRetrying()` → nouvelle `EventSource` via `retryKey`.
- Quand un event terminal (`run.completed` ou `run.error`) est reçu avant le drop, le comportement existant (`es.close()` dans le listener) reste inchangé.
- La branche "run terminé" du replay backend (`run:{id}:done`) est conservée — utile si le run se termine entre le drop et le retry.

**Ask First:** aucun.

**Never:**
- Ne pas modifier `AgentCard`, `BubbleBox`, `runStore`, `agentStatusStore` — l'infrastructure d'erreur existante suffit.
- Ne pas introduire de retry automatique côté frontend.
- Ne pas supprimer la branche `run:{id}:done` du backend.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| Drop pendant agent working | `runStore.status === 'running'`, un agent `working` | Agent → `error('Connexion SSE perdue')` ; `runStore` → `error` ; EventSource fermé ; BubbleBox Retry visible | — |
| Drop sans agent working (entre deux agents) | `runStore.status === 'running'`, aucun agent `working` | Seul `runStore` → `error('Connexion SSE perdue')` ; banner ControlCenter visible | — |
| `onerror` après `run.completed` reçu | `runStore.status === 'completed'` | Guard early-exit — aucune modification d'état | — |
| `onerror` après `run.error` reçu | `runStore.status === 'error'` | Guard early-exit — aucune modification d'état | — |
| Reconnect manuel (bouton Retry) | `retryKey` incrémenté → nouvel `useEffect` → nouvelle EventSource | Comportement normal inchangé | — |

</frozen-after-approval>

## Code Map

- `frontend/src/hooks/useSSEListener.ts` — `onerror` handler : fermeture ES + détection agent working + mise en erreur
- `backend/app/Http/Controllers/SseController.php` — supprimer la branche "run actif" (replay + retry: 3000) devenue inutile

## Tasks & Acceptance

**Execution:**
- [x] `frontend/src/hooks/useSSEListener.ts` -- Remplacer le corps de `es.onerror` par : (1) guard early-exit si `useRunStore.getState().status !== 'running'` ; (2) `es?.close()` ; (3) détecter l'entrée `(agentId, state)` avec `state.status === 'working'` dans `useAgentStatusStore.getState().agents` ; (4) si trouvée appeler `setAgentStatus(agentId, 'error', state.step, 'Connexion SSE perdue')` ; (5) `useRunStore.getState().setRunError('Connexion SSE perdue')` ; (6) `setConnectionStatus('error')` -- cœur du fix : empêche la boucle et active le Retry
- [x] `backend/app/Http/Controllers/SseController.php` -- Remplacer la branche `if (cache()->has("run:{$id}"))` (avec replay + retry: 3000) par le simple `if (cache()->has("run:{$id}")) { return; }` (comportement pré-fix) -- cette branche n'est plus utile depuis que le frontend ne reconnecte plus automatiquement

**Acceptance Criteria:**
- Given le stream SSE se coupe pendant qu'un agent est `working`, when `onerror` est déclenché, then l'EventSource est fermé, l'agent passe en `error` avec message "Connexion SSE perdue", la BubbleBox Retry s'affiche dans l'AgentCard, et aucune nouvelle tentative de connexion automatique n'est initiée
- Given le stream SSE se coupe entre deux agents (aucun `working`), when `onerror` est déclenché, then l'EventSource est fermé et la banner d'erreur globale (ControlCenter) s'affiche
- Given l'EventSource reçoit `run.completed` puis `onerror` se déclenche (timing rare), when `runStore.status === 'completed'`, then `onerror` sort immédiatement sans modifier l'état
- Given l'utilisateur clique "Relancer cette étape" après la perte de connexion, when `handleRetry` s'exécute, then le comportement retry existant (POST retry-step → setRetrying → nouvelle EventSource) est inchangé

## Suggested Review Order

- Guard early-exit + `es.close()` : entry point, stops all auto-reconnect on SSE drop
  [`useSSEListener.ts:88`](../../frontend/src/hooks/useSSEListener.ts#L88)

- Working-agent detection + mutually-exclusive error routing (BubbleBox vs global banner)
  [`useSSEListener.ts:96`](../../frontend/src/hooks/useSSEListener.ts#L96)

- Backend: active-run guard simplified to bare `return` — replay no longer needed
  [`SseController.php:53`](../../backend/app/Http/Controllers/SseController.php#L53)

## Design Notes

**Guard dans `onerror` :**
```typescript
es.onerror = () => {
  if (useRunStore.getState().status !== 'running') return

  es?.close()

  const agents = useAgentStatusStore.getState().agents
  const workingEntry = Object.entries(agents).find(([, s]) => s.status === 'working')
  if (workingEntry) {
    const [agentId, agentState] = workingEntry
    useAgentStatusStore.getState().setAgentStatus(agentId, 'error', agentState.step, 'Connexion SSE perdue')
  }

  useRunStore.getState().setRunError('Connexion SSE perdue')
  setConnectionStatus('error')
}
```

Le guard `status !== 'running'` garantit que si `es.close()` re-déclenche `onerror` (comportement browser rare), le second appel est ignoré.

## Verification

**Commands:**
- `cd backend && php artisan test` -- expected: 106/106 verts

**Manual checks:**
- Lancer un run, couper le réseau (DevTools → Network → Offline) pendant qu'un agent tourne : vérifier que la BubbleBox "Connexion SSE perdue" apparaît avec bouton Retry sur l'AgentCard concerné, qu'aucun nouveau `stream` request n'apparaît dans le Network tab, et que cliquer Retry recrée une connexion SSE et reprend l'exécution depuis le checkpoint
