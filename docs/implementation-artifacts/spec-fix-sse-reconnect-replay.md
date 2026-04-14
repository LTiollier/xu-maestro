---
title: 'SSE Reconnect Replay — buffer et rejoue sur reconnexion'
type: 'bugfix'
created: '2026-04-14'
status: 'done'
baseline_commit: '9ee50c012ab4478858fb6ac70a93ba124aa19e5f'
context: []
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** Quand le stream SSE se coupe (coupure réseau, timeout proxy), l'`EventSource` tente de se reconnecter automatiquement. Le backend retourne une réponse vide immédiatement (guards `run:{id}` ou `run:{id}:done`), ce qui déclenche une boucle de reconnexions rapides sans jamais recevoir d'événements.

**Approach:** Stocker les événements SSE sémantiques dans un log en cache par run. Sur reconnexion : rejouer le log intégral si le run est terminé (le frontend fermera l'EventSource sur `run.completed`/`run.error`) ; rejouer les événements passés et indiquer `retry: 3000` si le run est encore actif.

## Boundaries & Constraints

**Always:**
- Le log n'inclut PAS les `agent.log_line` (transients, volume élevé).
- Les handlers Zustand existants sont idempotents (overwrite) — rejouer des événements déjà traités est sans effet secondaire.
- La remise à zéro du log lors d'un retry checkpoint (dans `SseController`) doit précéder `executeFromCheckpoint()` pour éviter que des événements d'une exécution antérieure ne soient rejoués après un retry.

**Ask First:**
- Si un run est actif depuis plus de 1 heure (TTL cache `run:{id}` dépassé) mais que le log existe encore, rejouer ou laisser le comportement existant ?

**Never:**
- Ne pas changer l'architecture SSE (pas de WebSocket, pas de queue Laravel, pas de long-polling).
- Ne pas modifier `useSSEListener.ts` — la reconnexion automatique EventSource est gérée nativement par le navigateur.
- Ne pas logguer `agent.log_line`.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| Reconnect run terminé | `run:{id}:done` en cache, log non vide | Rejouer tous les événements → frontend reçoit `run.completed`/`run.error` → `es.close()` → boucle stoppée | Log vide → réponse vide fermée, EventSource tente une reconnexion supplémentaire (acceptable, log sera présent pour les nouveaux runs) |
| Reconnect run actif | `run:{id}` en cache | Rejouer les événements passés du log + `retry: 3000\n` + fermer | Log vide (début de run) → seul `retry: 3000` est envoyé |
| Premier appel normal | Ni `run:{id}` ni `run:{id}:done` | Exécution normale du run, inchangée | — |
| Retry depuis checkpoint | `run:{id}:retry_checkpoint` en cache | Effacer le log, puis exécution depuis checkpoint, inchangée | — |
| Reconnect run actif → run se termine entre deux tentatives | `run:{id}` puis `run:{id}:done` entre deux reconnects | Prochain reconnect frappe le branch `done` → rejoue tout incluant `run.completed` → fin propre | — |

</frozen-after-approval>

## Code Map

- `backend/app/Listeners/SseEmitter.php` — Ajouter `appendEventToLog()` ; factoriser la construction des payloads pour les loguer avant `json_encode`
- `backend/app/Http/Controllers/SseController.php` — Remplacer le guard vide par replay conditionnel ; effacer le log avant `executeFromCheckpoint()`

## Tasks & Acceptance

**Execution:**
- [x] `backend/app/Listeners/SseEmitter.php` -- Ajouter méthode privée `appendEventToLog(string $runId, string $type, array $payload): void` qui appende `['type' => $type, 'payload' => $payload]` à `cache()->get("run:{$runId}:event_log", [])` et repose la clé avec TTL 7200 -- persist les événements pour le replay
- [x] `backend/app/Listeners/SseEmitter.php` -- Dans `handleAgentStatusChanged`, `handleAgentBubble`, `handleAgentWaitingForInput`, `handleRunCompleted`, `handleRunError` : construire le tableau `$payload` en variable locale avant `json_encode`, puis appeler `$this->appendEventToLog($event->runId, '<event.type>', $payload)` -- les cinq handlers doivent loguer leur payload
- [x] `backend/app/Http/Controllers/SseController.php` -- Avant `$this->runService->executeFromCheckpoint(...)`, ajouter `cache()->forget("run:{$id}:event_log")` pour repartir d'un log propre sur retry -- évite que des événements d'erreur antérieurs ne soient rejoués dans un contexte de retry
- [x] `backend/app/Http/Controllers/SseController.php` -- Remplacer `if (cache()->has("run:{$id}") || cache()->has("run:{$id}:done")) { return; }` par deux branches distinctes : **(a)** si `run:{id}` actif → émettre chaque entrée du log comme `event: {type}\ndata: {json}\n\n` + flush, puis `echo "retry: 3000\n\n"` + flush + return ; **(b)** si `run:{id}:done` → émettre chaque entrée du log + return (sans directive retry — le frontend ferme via `run.completed`/`run.error`) -- cœur du fix

**Acceptance Criteria:**
- Given un stream SSE coupé pendant l'exécution (run actif), when l'EventSource se reconnecte, then le backend répond avec les événements déjà émis suivis d'un `retry: 3000`, and le frontend affiche l'état courant des agents, and l'EventSource retente dans ~3s
- Given un stream SSE coupé après la fin du run, when l'EventSource se reconnecte, then le backend rejoue tous les événements incluant `run.completed` ou `run.error`, and le frontend appelle `es.close()` sur réception de l'événement terminal, and la boucle de reconnexion s'arrête
- Given un retry depuis checkpoint (bouton Retry), when le stream SSE s'ouvre, then le log de l'exécution précédente est effacé avant le démarrage, and les événements rejoués ne contiennent pas les erreurs de l'exécution précédente
- Given un run sans aucun événement encore émis (toute première reconnexion au tout début), when le log est vide et le run est actif, then le backend envoie uniquement `retry: 3000` sans planter

## Design Notes

**Replay du log dans `SseController` :**
```php
// Branch run actif
if (cache()->has("run:{$id}")) {
    $log = cache()->get("run:{$id}:event_log", []);
    foreach ($log as $entry) {
        echo "event: {$entry['type']}\n";
        echo "data: " . json_encode($entry['payload']) . "\n\n";
        flush();
    }
    echo "retry: 3000\n\n";
    flush();
    return;
}
// Branch run terminé
if (cache()->has("run:{$id}:done")) {
    $log = cache()->get("run:{$id}:event_log", []);
    foreach ($log as $entry) {
        echo "event: {$entry['type']}\n";
        echo "data: " . json_encode($entry['payload']) . "\n\n";
        flush();
    }
    return;
}
```

**appendEventToLog dans SseEmitter :**
```php
private function appendEventToLog(string $runId, string $type, array $payload): void
{
    $key = "run:{$runId}:event_log";
    $log = cache()->get($key, []);
    $log[] = ['type' => $type, 'payload' => $payload];
    cache()->put($key, $log, 7200);
}
```

**Idempotence des stores Zustand :** `setAgentStatus`, `setAgentBubble`, `setRunCompleted`, `setRunError` écrasent l'état existant. Rejouer un événement déjà traité n'a aucun effet observable (sauf `setAgentBubble` qui réaffiche la même bulle — inoffensif).

## Verification

**Commands:**
- `cd backend && php artisan test` -- expected: tous les tests verts, 0 régression

**Manual checks:**
- Lancer un run, couper manuellement la connexion SSE (DevTools → Network → désactiver), puis la rétablir : vérifier que le diagramme affiche le bon état courant des agents
- Lancer un run jusqu'à completion, attendre 5s, recharger la page (ou rouvrir le SSE) : vérifier que `run.completed` est reçu et que le modal de fin s'affiche, et que les tentatives de reconnexion s'arrêtent
- Vérifier que le bouton Retry ne rejoue pas les erreurs de l'exécution précédente

## Suggested Review Order

**Cœur du fix — reconnexion et replay**

- Deux nouvelles branches remplacent le `return` vide ; entry point du changement
  [`SseController.php:53`](../../backend/app/Http/Controllers/SseController.php#L53)

- Replay run actif : émission du log + directive `retry: 3000` pour limiter la boucle
  [`SseController.php:54`](../../backend/app/Http/Controllers/SseController.php#L54)

- Replay run terminé : log intégral rejoué ; EventSource fermé par le frontend sur `run.completed`/`run.error`
  [`SseController.php:68`](../../backend/app/Http/Controllers/SseController.php#L68)

- Effacement du log avant retry checkpoint pour ne pas rejouer des erreurs antérieures
  [`SseController.php:33`](../../backend/app/Http/Controllers/SseController.php#L33)

**Alimentation du log — SseEmitter**

- `appendEventToLog` : méthode privée d'append en cache (TTL 7200, tous les handlers sauf `log_line`)
  [`SseEmitter.php:119`](../../backend/app/Listeners/SseEmitter.php#L119)

- Payload construit en tableau avant `json_encode` pour pouvoir l'appender au log
  [`SseEmitter.php:14`](../../backend/app/Listeners/SseEmitter.php#L14)

- `handleRunCompleted` : log avant émission SSE — garantit la présence du terminal event dans le replay
  [`SseEmitter.php:83`](../../backend/app/Listeners/SseEmitter.php#L83)

- `handleRunError` : même pattern ; `handleAgentLogLine` délibérément exclu du log
  [`SseEmitter.php:99`](../../backend/app/Listeners/SseEmitter.php#L99)
