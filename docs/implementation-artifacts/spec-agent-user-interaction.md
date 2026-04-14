---
title: 'Interaction agent-utilisateur pendant un run'
type: 'feature'
created: '2026-04-14'
status: 'done'
baseline_commit: '6edb05d5ce28b8539a73faea16a21be540cf11d3'
context: []
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problème :** Les agents s'exécutent de manière entièrement autonome — il n'existe aucun mécanisme pour qu'un agent pose une question à l'utilisateur et attende sa réponse avant de continuer.

**Approche :** Ajouter un statut `waiting_for_input` dans le cycle d'exécution : quand un agent retourne ce statut avec une question, le backend met l'exécution en pause, diffuse l'événement SSE, et attend une réponse via un nouvel endpoint. La réponse est injectée dans le contexte partagé et l'exécution reprend.

## Boundaries & Constraints

**Always:**
- L'agent attend en boucle (polling cache) jusqu'à réception de la réponse ou annulation du run.
- La réponse est stockée dans le contexte partagé du run pour les agents suivants.
- Visuellement : le statut `waiting_for_input` doit être clairement distinct des autres statuts (couleur, icône, badge spécifique).
- Le composant de réponse (textarea + bouton submit) apparaît directement sous la carte de l'agent concerné.

**Ask First:**
- Timeout d'attente de réponse : combien de minutes avant d'échouer le run ? (propose 15 min par défaut)
- Le format attendu dans l'output de l'agent : `{ "status": "waiting_for_input", "question": "...", "output": "...", "next_action": null, "errors": [] }` — confirmer ?

**Never:**
- Ne pas interrompre/modifier les agents qui ne demandent pas d'input.
- Ne pas implémenter un système de chat multi-tour (une seule Q&R par agent attendant).
- Ne pas utiliser WebSocket — rester sur SSE + polling cache.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| Agent pose une question | Agent retourne `waiting_for_input` + `question` | SSE `agent.waiting_for_input` émis, UI affiche le composant de réponse | — |
| Utilisateur soumet une réponse | `POST /api/runs/{id}/answer` avec `{ agentId, answer }` | Réponse mise en cache, exécution reprend, agent passe `done` | 422 si `answer` vide |
| Run annulé pendant l'attente | Cache `run:{id}:cancelled` détecté | Polling s'arrête, RunError émis | — |
| Timeout dépassé (15 min) | Aucune réponse reçue après le seuil | RunError avec message "Délai de réponse dépassé" | Checkpoint écrit (retry possible) |
| Double-soumission | `POST /api/runs/{id}/answer` envoyé deux fois | La première réponse est utilisée (cache already set = no-op) | 409 ou 200 idempotent |

</frozen-after-approval>

## Code Map

- `backend/app/Services/RunService.php` -- Boucle principale d'exécution ; ajouter gestion du statut `waiting_for_input` + polling de réponse
- `backend/app/Events/AgentWaitingForInput.php` -- Nouvel événement SSE à créer
- `backend/app/Listeners/SseEmitter.php` -- Ajouter `handleAgentWaitingForInput()`
- `backend/app/Http/Controllers/RunController.php` -- Ajouter endpoint `POST /api/runs/{id}/answer`
- `backend/routes/api.php` -- Enregistrer la nouvelle route
- `frontend/src/types/sse.types.ts` -- Ajouter `waiting_for_input` à `AgentStatus` + `AgentWaitingForInputEvent`
- `frontend/src/stores/agentStatusStore.ts` -- Ajouter champ `question` dans `AgentState`, gérer le nouveau statut
- `frontend/src/hooks/useSSEListener.ts` -- Gérer l'événement `agent.waiting_for_input`
- `frontend/src/components/AgentCard.tsx` -- Ajouter styles pour `waiting_for_input` + afficher `QuestionBubble`
- `frontend/src/components/QuestionBubble.tsx` -- Nouveau composant : question + textarea + bouton submit

## Tasks & Acceptance

**Execution:**
- [x] `backend/app/Events/AgentWaitingForInput.php` -- Créer la classe d'événement avec `runId`, `agentId`, `question`, `step` -- cohérence avec les autres events
- [x] `backend/app/Listeners/SseEmitter.php` -- Ajouter `handleAgentWaitingForInput()` émettant `event: agent.waiting_for_input` -- même pattern que les autres handlers
- [x] `backend/app/Services/RunService.php` -- Dans `validateJsonOutput`, accepter `waiting_for_input` comme statut valide ; dans la boucle `executeAgents`, après succès CLI : si `$output['status'] === 'waiting_for_input'`, émettre l'event, écrire le checkpoint, puis boucler en polling `run:{runId}:user_answer:{agentId}` (sleep 1s, max 900 iterations = 15 min), annuler si `cancelled` cache flag ; à réception : injecter Q&R dans `$completedAgents` context puis `continue` la boucle
- [x] `backend/app/Http/Controllers/RunController.php` -- Ajouter `answer(Request $request, string $runId)` : valider `agentId` + `answer` (non vide), vérifier que le run est actif, stocker `run:{runId}:user_answer:{agentId}` en cache (TTL 3600), retourner 202
- [x] `backend/routes/api.php` -- Enregistrer `POST /runs/{id}/answer` → `RunController@answer`
- [x] `frontend/src/types/sse.types.ts` -- Ajouter `'waiting_for_input'` à `AgentStatus` ; ajouter interface `AgentWaitingForInputEvent` ; ajouter `AGENT_WAITING_FOR_INPUT` dans `SSE_EVENT_TYPES`
- [x] `frontend/src/stores/agentStatusStore.ts` -- Ajouter `question: string` dans `AgentState` (défaut `''`) ; gérer le nouveau statut ; ajouter `setAgentQuestion` action
- [x] `frontend/src/hooks/useSSEListener.ts` -- Ajouter case pour `agent.waiting_for_input`
- [x] `frontend/src/components/QuestionBubble.tsx` -- Créer composant : question + textarea contrôlé + bouton "Répondre" + POST answer + style violet
- [x] `frontend/src/components/AgentNode.tsx` + `AgentDiagram.tsx` -- Ajouter `waiting_for_input` styles (violet glow, ring, icône MessageCircleQuestion) ; afficher `QuestionBubble` ; passer `question` depuis le store

**Acceptance Criteria:**
- Given un agent retourne `{ "status": "waiting_for_input", "question": "Quel est ton prénom ?" }`, when le backend traite l'output, then un événement SSE `agent.waiting_for_input` est émis et l'exécution est suspendue
- Given l'événement SSE reçu, when le frontend le traite, then la carte de l'agent affiche le statut `waiting_for_input` (badge violet, glow violet) et un composant de réponse apparaît sous la carte
- Given l'utilisateur saisit une réponse et soumet, when `POST /api/runs/{id}/answer` est appelé, then la réponse est stockée, l'exécution reprend, et l'agent passe en `done`
- Given le run est annulé pendant l'attente, when le polling détecte le flag `cancelled`, then un RunError est émis et l'exécution s'arrête proprement
- Given le timeout de 15 min est atteint sans réponse, when le polling expire, then un RunError "Délai de réponse dépassé" est émis et un checkpoint est écrit

## Design Notes

**Format JSON attendu de l'agent :**
```json
{
  "step": "Question posée à l'utilisateur",
  "status": "waiting_for_input",
  "question": "Quel est le nom du client final ?",
  "output": "",
  "next_action": null,
  "errors": []
}
```

**Clés cache utilisées :**
- `run:{runId}:pending_question:{agentId}` — question en attente (TTL 3600)
- `run:{runId}:user_answer:{agentId}` — réponse de l'utilisateur (TTL 3600)

**Injection dans le contexte :** La Q&R est ajoutée comme entrée dans `$completedAgents` sous la forme `["agent" => $agentId, "question" => ..., "answer" => ...]` pour que `buildAgentContext()` puisse l'inclure dans le prompt des agents suivants.

## Verification

**Manual checks:**
- Lancer un run avec un agent configuré pour retourner `waiting_for_input` → vérifier badge violet + textarea visible
- Soumettre une réponse → vérifier que l'exécution reprend et que l'agent passe `done`
- Annuler le run pendant l'attente → vérifier qu'aucune exécution supplémentaire n'a lieu

## Suggested Review Order

**Entrée principale — logique de pause et polling**

- Bloc `waiting_for_input` : emit SSE → checkpoint → polling → injection Q&R
  [`RunService.php:259`](../../backend/app/Services/RunService.php#L259)

- Validation que `question` est une string non-vide ; acceptation du statut
  [`RunService.php:481`](../../backend/app/Services/RunService.php#L481)

**Endpoint de réponse utilisateur**

- `answer()` : validation, idempotence, cache 3600s
  [`RunController.php:152`](../../backend/app/Http/Controllers/RunController.php#L152)

- Cleanup `user_answer` + `pending_question` lors du retry pour éviter réponse réutilisée
  [`RunController.php:138`](../../backend/app/Http/Controllers/RunController.php#L138)

- Route enregistrée
  [`api.php:15`](../../backend/routes/api.php#L15)

**Événement SSE et listener**

- Classe event `AgentWaitingForInput` (même pattern que les autres)
  [`AgentWaitingForInput.php:1`](../../backend/app/Events/AgentWaitingForInput.php#L1)

- Handler SSE émettant `event: agent.waiting_for_input`
  [`SseEmitter.php:41`](../../backend/app/Listeners/SseEmitter.php#L41)

- Enregistrement du listener
  [`AppServiceProvider.php:28`](../../backend/app/Providers/AppServiceProvider.php#L28)

**Frontend — composant de réponse**

- `QuestionBubble` : question + textarea + submit + disabled après envoi
  [`QuestionBubble.tsx:1`](../../frontend/src/components/QuestionBubble.tsx#L1)

- `AgentNode` : styles violet, icône `MessageCircleQuestion`, affichage `QuestionBubble`
  [`AgentNode.tsx:23`](../../frontend/src/components/AgentNode.tsx#L23)

**Frontend — plomberie SSE et store**

- Handler SSE `AGENT_WAITING_FOR_INPUT` → store
  [`useSSEListener.ts:36`](../../frontend/src/hooks/useSSEListener.ts#L36)

- `setAgentQuestion` + champ `question` dans le store
  [`agentStatusStore.ts:5`](../../frontend/src/stores/agentStatusStore.ts#L5)

**Types**

- `AgentStatus` étendu, `AgentWaitingForInputEvent`, `AGENT_WAITING_FOR_INPUT`
  [`sse.types.ts:1`](../../frontend/src/types/sse.types.ts#L1)

- Champ `question` dans `AgentState`
  [`run.types.ts:6`](../../frontend/src/types/run.types.ts#L6)
