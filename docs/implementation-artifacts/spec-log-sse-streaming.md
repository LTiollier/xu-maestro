---
title: 'SSE streaming du log de session (remplacement du polling HTTP)'
type: 'refactor'
created: '2026-04-15'
status: 'done'
baseline_commit: '95679011df0234288b2bf0bb5399592bf57584ac'
context: []
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** RunSidebar interroge `/api/runs/{id}/log` en JSON toutes les 2 secondes pendant l'exécution d'un run, entraînant une latence réseau inutile et des requêtes répétées même quand rien n'a changé.

**Approach:** Créer un endpoint SSE `/api/runs/{id}/log-stream` qui pousse uniquement les nouveaux bytes de `session.md` dès qu'ils apparaissent (offset tracking). Le frontend abandonne le polling et ouvre un EventSource sur ce nouvel endpoint. L'ancien endpoint JSON `/log` est supprimé.

## Boundaries & Constraints

**Always:**
- Événement `log.append` (data: `{ chunk: string }`) pour chaque nouveau bloc de bytes ; `log.done` (data: `{}`) quand le run est terminé.
- Pour les runs déjà terminés : envoyer l'intégralité du contenu en un seul `log.append` puis `log.done` immédiatement, puis fermer.
- Le backend ferme la connexion après `log.done` — aucun keepalive superflu.
- Le frontend ferme l'EventSource sur `log.done` ou sur erreur (`onerror`).
- Supprimer l'ancien endpoint JSON `GET /runs/{id}/log` et la méthode `RunController@log` (seul RunSidebar le consommait).

**Ask First:**
- Si `session.md` n'existe pas encore au moment de la connexion SSE et que le run est déjà marqué `done` (race condition), faut-il envoyer `log.done` sans contenu ou attendre ?

**Never:**
- Ne pas renvoyer le contenu complet à chaque tick (overhead inutile).
- Ne pas réutiliser `SseController` (couplé à l'exécution du run).
- Ne pas utiliser `inotify` ou extensions PHP non standard (polling 500 ms, plus portable).

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| Run en cours | `run:{id}:done` absent, session.md grandit | Flux de `log.append` en continu jusqu'à fin du run, puis `log.done` | `onerror` → fermer EventSource |
| Run terminé, reconnexion | `run:{id}:done` présent | Contenu complet en un seul `log.append` + `log.done` immédiat | — |
| session.md absent au démarrage | Fichier pas encore créé | Boucle 500 ms jusqu'à création ou run done | Si done sans fichier → `log.done` sans contenu |
| Run inconnu | Path introuvable en cache et sur FS | 404 abort | Frontend `onerror` → fermer |

</frozen-after-approval>

## Code Map

- `backend/app/Http/Controllers/LogSseController.php` -- nouveau controller SSE dédié au streaming des logs
- `backend/routes/api.php` -- ajouter `GET /runs/{id}/log-stream`, supprimer `GET /runs/{id}/log`
- `backend/app/Http/Controllers/RunController.php` -- supprimer la méthode `log()` (et import `File` si inutilisé)
- `frontend/src/app/api/runs/[id]/log-stream/route.ts` -- proxy Next.js calqué sur `stream/route.ts`
- `frontend/src/components/RunSidebar.tsx` -- remplacer le polling useEffect par un EventSource

## Tasks & Acceptance

**Execution:**
- [x] `backend/app/Http/Controllers/LogSseController.php` -- créer avec méthode `stream(string $id)` : résolution du runPath (même logique que RunController@log), StreamedResponse avec headers SSE via SseStreamService@setHeaders, boucle tail 500 ms avec offset tracking, émission `log.append`/`log.done`, fermeture propre
- [x] `backend/routes/api.php` -- ajouter `Route::get('/runs/{id}/log-stream', [LogSseController::class, 'stream'])` ; supprimer la ligne `/runs/{id}/log`
- [x] `backend/app/Http/Controllers/RunController.php` -- supprimer la méthode `log()` et nettoyer l'import `File` s'il devient inutilisé
- [x] `frontend/src/app/api/runs/[id]/log-stream/route.ts` -- créer le proxy Next.js (force-dynamic, GET, forward vers `${BACKEND_URL}/api/runs/{id}/log-stream`, headers SSE)
- [x] `frontend/src/components/RunSidebar.tsx` -- remplacer les deux useEffects de polling par un seul useEffect : ouvrir EventSource sur `/api/runs/${runId}/log-stream`, accumuler les chunks (`setLogContent(prev => prev + chunk)`), fermer sur `log.done` ou `onerror`

**Acceptance Criteria:**
- Given un run en cours, when RunSidebar monte, then le contenu du log s'affiche en temps réel et aucun setInterval n'est actif (vérifié via DevTools Network : une seule connexion EventSource)
- Given un run terminé, when RunSidebar monte, then le contenu complet s'affiche en une seule connexion SSE qui se ferme immédiatement après `log.done`
- Given une erreur réseau sur l'EventSource, when `onerror` se déclenche, then l'EventSource est fermé sans boucle de reconnexion infinie
- Given la suppression de la route JSON, when `GET /api/runs/{id}/log` est appelé, then le backend retourne 404

## Spec Change Log

## Design Notes

**Offset tracking :** la variable `$offset` (int, bytes déjà envoyés) vit dans la closure de la StreamedResponse. À chaque tick : `$content = @file_get_contents($sessionPath)` → `$new = substr($content, $offset)` → si non-vide, envoyer `log.append` et incrémenter `$offset`. Aucun état persistant nécessaire.

**Accumulation côté frontend :** `setLogContent(prev => prev + chunk)` — les chunks sont appendés, la logique de parsing existante (`parseLogs()`) s'applique toujours sur le contenu complet reconstruit.

## Verification

**Commands:**
- `cd backend && php artisan route:list --path=runs` -- expected: `/runs/{id}/log-stream` présent, `/runs/{id}/log` absent
- `cd frontend && npx tsc --noEmit` -- expected: 0 erreurs

**Manual checks (if no CLI):**
- Ouvrir RunSidebar sur un run actif → DevTools → Network → aucun XHR répété toutes les 2 s, une seule connexion `log-stream` de type EventStream ouverte

## Suggested Review Order

**SSE protocol — cœur du streaming**

- Boucle tail avec offset tracking ; point d'entrée de toute la logique
  [`LogSseController.php:31`](../../backend/app/Http/Controllers/LogSseController.php#L31)

- Lecture finale avant `log.done` — évite la perte du dernier chunk (race P1)
  [`LogSseController.php:55`](../../backend/app/Http/Controllers/LogSseController.php#L55)

- `ignore_user_abort` + `connection_aborted()` — arrêt propre si client déconnecté (P2)
  [`LogSseController.php:25`](../../backend/app/Http/Controllers/LogSseController.php#L25)

**Frontend — consommateur SSE**

- EventSource avec accumulation de chunks ; `retryKey` force la reprise après retry (P4)
  [`RunSidebar.tsx:26`](../../frontend/src/components/RunSidebar.tsx#L26)

- `log.append` gardé par try/catch — frames malformées ignorées sans crash (P5)
  [`RunSidebar.tsx:33`](../../frontend/src/components/RunSidebar.tsx#L33)

**Routage**

- Nouvelle route `log-stream`, ancienne route `log` supprimée
  [`api.php:12`](../../backend/routes/api.php#L12)

- Proxy Next.js — propage le status HTTP du backend (P3)
  [`route.ts:18`](../../frontend/src/app/api/runs/[id]/log-stream/route.ts#L18)

**Nettoyage**

- `resolveRunPath` — fallback FS identique à l'ancien `RunController@log`
  [`LogSseController.php:87`](../../backend/app/Http/Controllers/LogSseController.php#L87)

- Méthode `log()` supprimée de RunController
  [`RunController.php:193`](../../backend/app/Http/Controllers/RunController.php#L193)
