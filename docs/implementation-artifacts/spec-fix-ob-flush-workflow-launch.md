---
title: 'Fix ob_flush sans buffer au lancement d'un workflow'
type: 'bugfix'
created: '2026-04-12'
status: 'done'
baseline_commit: 'd3c58f7155d8dad240d63facd428e11d845936d3'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** Au lancement d'un workflow, PHP lance `ob_flush(): Failed to flush buffer. No buffer to flush` parce que `SseStreamService::setHeaders()` vide tous les buffers d'output (`ob_end_clean()`) sans en recréer un, et que `SseEmitter` + `sendKeepAlive()` appellent ensuite `ob_flush()` sur un buffer inexistant.

**Approach:** Supprimer les appels `ob_flush()` dans `SseStreamService::sendKeepAlive()` et les 4 handlers de `SseEmitter` — `ob_implicit_flush(true)` étant déjà activé dans `setHeaders()`, seul `flush()` est nécessaire pour forcer l'envoi SSE au client.

## Boundaries & Constraints

**Always:** Conserver `flush()` après chaque bloc SSE pour garantir la livraison immédiate au client. Ne pas modifier le comportement SSE observable côté client.

**Ask First:** Si un buffer doit être ré-activé ultérieurement (ex. middleware tiers qui wrappe la réponse).

**Never:** Supprimer `flush()`. Utiliser `@ob_flush()` pour masquer l'erreur. Ajouter `ob_start()` dans `setHeaders()` sans valider que ça n'introduit pas de buffering indésirable.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| Lancement nominal | Run lancé, SSE actif, `ob_implicit_flush(true)`, aucun buffer actif | Événements SSE envoyés sans exception | N/A |
| Keep-alive ping | Connexion idle, `sendKeepAlive()` appelé | `: ping\n\n` envoyé sans erreur | N/A |
| Fin de run | `RunCompleted` dispatché | `run.completed` envoyé, connexion se ferme proprement | N/A |

</frozen-after-approval>

## Code Map

- `backend/app/Services/SseStreamService.php` -- `setHeaders()` vide les buffers + active `ob_implicit_flush`; `sendKeepAlive()` contient l'appel `ob_flush()` fautif
- `backend/app/Listeners/SseEmitter.php` -- 4 handlers SSE, chacun avec `ob_flush()` + `flush()`

## Tasks & Acceptance

**Execution:**
- [x] `backend/app/Services/SseStreamService.php` -- Supprimer `ob_flush();` dans `sendKeepAlive()` (ligne 18), garder `flush();` -- `ob_flush()` échoue quand aucun buffer n'est actif après le drain de `setHeaders()`
- [x] `backend/app/Listeners/SseEmitter.php` -- Supprimer `ob_flush();` dans les 4 handlers (`handleAgentStatusChanged` l.25, `handleAgentBubble` l.41, `handleRunCompleted` l.59, `handleRunError` l.77), garder `flush();` dans chacun -- même raison

**Acceptance Criteria:**
- Given un workflow lancé avec le SSE actif, when un agent change d'état, then l'événement SSE est reçu par le client sans aucune exception PHP dans les logs
- Given `sendKeepAlive()` est appelé pendant une connexion idle, when la méthode s'exécute, then `: ping` est envoyé sans exception `ob_flush`
- Given `ob_get_level() === 0` (aucun buffer actif), when un handler SSE émet un événement, then aucune erreur `ob_flush` n'est levée

## Spec Change Log

## Verification

**Commands:**
- `cd backend && php artisan test --filter=SseEmitterTest` -- expected: tous les tests passent (si des tests existent)

**Manual checks (si no CLI):**
- Lancer un workflow depuis l'UI → vérifier qu'aucune ligne `ob_flush(): Failed to flush buffer` n'apparaît dans `backend/storage/logs/laravel.log`
- Vérifier que les événements SSE arrivent bien dans la sidebar (agent status, bulles, run.completed)

## Suggested Review Order

- `setHeaders()` vide tous les buffers sans en recréer — racine du bug
  [`SseStreamService.php:7`](../../backend/app/Services/SseStreamService.php#L7)

- `sendKeepAlive()` — `ob_flush()` supprimé, `flush()` seul suffit
  [`SseStreamService.php:15`](../../backend/app/Services/SseStreamService.php#L15)

- Même correction sur les 4 handlers SSE — `handleAgentStatusChanged`
  [`SseEmitter.php:23`](../../backend/app/Listeners/SseEmitter.php#L23)

- `handleAgentBubble` — même pattern
  [`SseEmitter.php:38`](../../backend/app/Listeners/SseEmitter.php#L38)

- `handleRunCompleted` — même pattern
  [`SseEmitter.php:54`](../../backend/app/Listeners/SseEmitter.php#L54)

- `handleRunError` — même pattern
  [`SseEmitter.php:71`](../../backend/app/Listeners/SseEmitter.php#L71)
