---
title: 'Skip conditionnel d'un agent par son prédécesseur'
type: 'feature'
created: '2026-04-14'
status: 'done'
baseline_commit: '9e93df368662d2c40c501a2adb5b234f9894c87e'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** Tous les agents s'exécutent systématiquement, même si la requête ne les nécessite pas (ex. une question sans besoin de code ne devrait pas déclencher l'agent dev).

**Approach:** Ajouter `skippable: true` à un agent YAML. Si l'agent précédent inclut `"next_action": "skip_next"` dans sa réponse JSON, l'engine saute cet agent et émet un événement `skipped` visible en frontend.

## Boundaries & Constraints

**Always:**
- `skippable: true` est nécessaire sur l'agent cible pour que le skip soit respecté — un agent sans ce flag ne peut jamais être sauté, même si `next_action: "skip_next"` est émis.
- Un seul `skip_next` = saut du prochain agent uniquement (pas en cascade).
- Quand le prochain agent est `skippable`, injecter automatiquement l'option dans le format de sortie requis du contexte de l'agent courant — pas besoin de modifier le `system_prompt` manuellement dans le YAML.
- `mandatory` (retry on failure) et `skippable` sont orthogonaux : un agent peut être les deux.

**Ask First:**
- Si un agent est le dernier du workflow et émet `skip_next`, ignorer silencieusement ou logger ? (proposer : ignorer silencieusement)

**Never:**
- Modifier le comportement des agents sans `skippable: true`.
- Ajouter un nouveau champ JSON au contrat de sortie — réutiliser `next_action` existant.
- Afficher les agents skippés autrement que dans le diagramme/carte déjà existants.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| Skip déclenché | agent PM retourne `next_action: "skip_next"`, agent dev a `skippable: true` | dev skippé, `AgentStatusChanged(skipped)` émis, run continue | N/A |
| Skip ignoré — agent non-skippable | `next_action: "skip_next"`, dev sans `skippable` | signal ignoré, dev s'exécute normalement | N/A |
| Pas de signal skip | `next_action: null` | dev s'exécute normalement | N/A |
| Dernier agent émet skip | `next_action: "skip_next"` sur le dernier agent | ignoré silencieusement, run se termine normalement | N/A |

</frozen-after-approval>

## Code Map

- `backend/app/Services/RunService.php` -- boucle `executeAgents()` (l.113), `buildAgentContext()` (l.299), logique de skip à insérer post-`validateJsonOutput`
- `backend/app/Services/YamlService.php` -- parsing/validation des agents YAML, ajouter support du champ `skippable`
- `backend/app/Events/AgentStatusChanged.php` -- event SSE, `status` est une string libre — aucun changement nécessaire
- `frontend/src/types/sse.types.ts` -- type `AgentStatus`, ajouter `'skipped'`
- `frontend/src/stores/agentStatusStore.ts` -- `setAgentStatus`, gérer `progress` et `currentTask` pour `skipped`
- `frontend/src/components/AgentCard.tsx` -- styles par status, ajouter `skipped`
- `frontend/src/components/AgentNode.tsx` -- styles par status, ajouter `skipped`
- `backend/tests/Unit/RunServiceTest.php` -- tests unitaires existants

## Tasks & Acceptance

**Execution:**
- [x] `backend/app/Services/YamlService.php` -- Accepter `skippable: true|false` (optionnel, défaut `false`) dans la définition d'un agent sans casser la validation existante
- [x] `backend/app/Services/RunService.php` -- Dans `buildAgentContext()`, ajouter un param `bool $nextIsSkippable = false` ; si `true`, remplacer `"next_action": null` par `"next_action": null|"skip_next"` avec une note courte sur quand utiliser `skip_next`
- [x] `backend/app/Services/RunService.php` -- Dans `executeAgents()`, après `validateJsonOutput()` : si `$decoded['next_action'] === 'skip_next'` ET que le prochain agent a `skippable: true`, émettre `AgentStatusChanged($runId, $nextAgentId, 'skipped', $nextStepIndex, '')`, ajouter `$nextAgentId` à `$completedAgents`, et incrémenter `$stepIndex` pour sauter l'itération suivante
- [x] `backend/app/Services/RunService.php` -- Transmettre `$nextIsSkippable` à `buildAgentContext()` en lisant `$workflow['agents'][$stepIndex + 1]['skippable'] ?? false`
- [x] `frontend/src/types/sse.types.ts` -- Ajouter `'skipped'` à `AgentStatus`
- [x] `frontend/src/stores/agentStatusStore.ts` -- Pour `status === 'skipped'` : `progress = 0`, `currentTask` inchangé
- [x] `frontend/src/components/AgentCard.tsx` -- Ajouter entrée `skipped` dans `wrapperStyles`, `cardRingStyles`, `badgeStyles` (suggestion : `opacity-40`, `ring-zinc-500`, `bg-zinc-500 text-white`)
- [x] `frontend/src/components/AgentNode.tsx` -- Ajouter état visuel `skipped` cohérent avec `AgentCard`
- [x] `backend/tests/Unit/RunServiceTest.php` -- Ajouter un test : deux agents, second avec `skippable: true`, premier retourne `next_action: "skip_next"` → second non exécuté, `AgentStatusChanged(skipped)` émis

**Acceptance Criteria:**
- Given un workflow avec PM (sans `skippable`) et dev (`skippable: true`), when PM retourne `next_action: "skip_next"`, then dev n'est pas exécuté et un événement SSE `skipped` est émis pour dev
- Given un workflow avec PM et dev (`skippable: true`), when PM retourne `next_action: null`, then dev s'exécute normalement
- Given un agent dev sans `skippable: true`, when PM retourne `next_action: "skip_next"`, then dev s'exécute quand même
- Given un agent avec `skippable: true` dans le YAML, when le contexte de l'agent précédent est construit, then le format de sortie requis mentionne `skip_next` comme valeur possible de `next_action`
- Given un agent skippé, when le frontend reçoit l'événement SSE, then la carte de l'agent affiche le badge `skipped` avec style zinc/grisé

## Spec Change Log

## Design Notes

Exemple de format de sortie injecté quand le prochain agent est skippable :

```
## Required output format
Respond with ONLY this JSON object — no markdown, no code block, no extra text:
{"step": "<brief description>", "status": "done", "output": "<your full response>", "next_action": null, "errors": []}

Note: set "next_action" to "skip_next" if you determine the next agent is not needed for this request.
```

## Verification

**Commands:**
- `cd backend && php artisan test --filter RunServiceTest` -- expected: all tests pass including new skip test
- `cd frontend && npm run type-check` -- expected: no TypeScript errors

## Suggested Review Order

**Engine d'exécution — logique de skip (entry point)**

- Guard de skip en tête d'itération : signal + skippable = saut + event SSE
  [`RunService.php:123`](../../backend/app/Services/RunService.php#L123)

- Détection du signal après exécution — met à jour le flag pour l'itération suivante
  [`RunService.php:249`](../../backend/app/Services/RunService.php#L249)

- Calcul de `$nextIsSkippable` avant la boucle de retry
  [`RunService.php:145`](../../backend/app/Services/RunService.php#L145)

**Contrat de sortie — injection conditionnelle**

- `buildAgentContext` étendu : ajoute la note `skip_next` si le prochain est skippable
  [`RunService.php:318`](../../backend/app/Services/RunService.php#L318)

- Appel avec le flag `$nextIsSkippable` transmis
  [`RunService.php:184`](../../backend/app/Services/RunService.php#L184)

**Frontend — types et composants**

- Type `AgentStatus` étendu avec `'skipped'`
  [`sse.types.ts:1`](../../frontend/src/types/sse.types.ts#L1)

- Styles zinc/grisé pour l'état `skipped` dans AgentCard
  [`AgentCard.tsx:16`](../../frontend/src/components/AgentCard.tsx#L16)

- État `skipped` avec icône `SkipForward` dans AgentNode
  [`AgentNode.tsx:14`](../../frontend/src/components/AgentNode.tsx#L14)

**Périphériques — tests et config**

- Nouveau test : PM signale `skip_next`, dev (`skippable: true`) non exécuté
  [`RunServiceTest.php:317`](../../backend/tests/Unit/RunServiceTest.php#L317)

- Nouveau test : signal ignoré sans `skippable: true`
  [`RunServiceTest.php:360`](../../backend/tests/Unit/RunServiceTest.php#L360)

- Exemple YAML avec `skippable: true` sur l'agent dev
  [`simple-workflow.yaml:15`](../../workflows/simple-workflow.yaml#L15)
