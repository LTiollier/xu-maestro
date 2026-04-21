---
title: 'Exécution Parallèle — Fan-out / Fan-in'
type: 'feature'
created: '2026-04-21'
status: 'done'
baseline_commit: 'e9d89e64b9c4f225fee4755d4af4b95956ddb879'
context: []
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** Les agents sans dépendances directes (ex. Frontend et Backend) sont exécutés en séquence, doublant inutilement le temps du run.

**Approach:** Introduire un bloc `parallel:` dans le YAML. Le moteur Laravel lance ces agents simultanément via `Process::pool()`. Un Join bloque le pipeline jusqu'à ce que tous les agents du groupe soient terminés. Chaque agent écrit son output dans un fichier temporaire isolé, fusionné dans `session.md` après le Join.

## Boundaries & Constraints

**Always:**
- Chaque agent parallèle a son timeout indépendant.
- Les événements SSE peuvent envoyer des statuts `working` simultanés pour plusieurs agents.
- Les agents parallèles héritent du même snapshot de `session.md` au démarrage du groupe.
- Chaque agent écrit son output dans `run/{id}/tmp/{agentId}.md` ; la fusion dans `session.md` suit l'ordre de déclaration YAML (déterministe).
- Si un agent non-mandatory échoue, le groupe continue ; une bulle d'avertissement SSE est émise.
- Le checkpoint est écrit une seule fois après la complétion de tout le groupe (groupe = étape atomique).

**Ask First:**
- Faut-il afficher les live logs de plusieurs agents parallèles simultanément dans la sidebar, ou masquer les logs des agents non-actifs ?

**Never:**
- `interactive: true` sur un agent dans un bloc `parallel:` (confusion UI avec plusieurs `waiting_for_input` simultanés).
- `loop:` sur un agent parallèle.
- Groupes `parallel:` imbriqués.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| Tous réussissent | 3 agents complètent | Pipeline attend le dernier puis passe à l'étape suivante | N/A |
| Agent mandatory échoue | 1 error (mandatory) + 2 success | Groupe entier en erreur, run s'arrête | Exception après pool wait |
| Agent non-mandatory échoue | 1 error (non-mandatory) + 2 success | Groupe continue, warning bubble SSE émis | Logger warning, continuer |
| Collision contexte | 2 agents écrivent simultanément | Chacun écrit dans son propre fichier temp | N/A (par construction) |
| Timeout individuel | 1 agent dépasse son timeout | Cet agent passe en error ; si mandatory → groupe fail | Timeout per-agent dans le pool |
| Schema invalide | `interactive: true` + `parallel:` | YamlService retourne une erreur de validation | Erreur bloquante avant run |

</frozen-after-approval>

## Code Map

- `backend/app/Services/RunService.php` -- boucle principale d'exécution ; détecter les blocs parallèles et dispatcher via Process::pool()
- `backend/app/Services/YamlService.php` -- validation du schéma YAML ; ajouter le support du bloc `parallel:` et ses contraintes
- `backend/app/Services/ArtifactService.php` -- ajouter `writeAgentTempOutput()` et `mergeParallelOutputs()` pour accumulation sans collision
- `backend/app/Services/CheckpointService.php` -- traiter le groupe parallèle comme étape atomique au checkpoint
- `frontend/src/stores/agentStatusStore.ts` -- retirer la contrainte single-working dans `setAgentLiveLog` (ligne 42)
- `frontend/src/components/Sidebar.tsx` -- indicateur visuel de groupe (bracket gauche) pour les agents parallèles

## Tasks & Acceptance

**Execution:**
- [x] `backend/app/Services/YamlService.php` -- valider le bloc `parallel:` comme liste d'agents ; rejeter si un agent du groupe a `interactive: true` ou `loop:`
- [x] `backend/app/Services/ArtifactService.php` -- ajouter `writeAgentTempOutput()` et `mergeParallelOutputs()` — fichiers temp par agent + fusion déterministe dans session.md
- [x] `backend/app/Drivers/DriverInterface.php` + `ClaudeDriver.php` + `GeminiDriver.php` -- ajouter `startAsync()` ; `execute()` l'appelle en interne — tous les processus démarrent en fond avant d'être attendus
- [x] `backend/app/Services/RunService.php` -- détecter les blocs `parallel:` ; `executeParallelGroup()` : phase 1 startAsync tous, phase 2 wait séquentiel ; SSE events par agent ; fusion context ; échecs mandatory vs non-mandatory
- [x] `backend/app/Services/CheckpointService.php` -- aucun changement requis : le groupe est traité comme étape atomique via currentStep/currentAgent=null dans RunService
- [x] `frontend/src/stores/agentStatusStore.ts` -- guard early-return retiré ligne 42
- [x] `frontend/src/components/Sidebar.tsx` -- rendu des `ParallelGroup` avec bordure gauche ; compteur via `countAgents()`
- [x] `frontend/src/types/workflow.types.ts` -- ajout `ParallelGroup`, `WorkflowStep`, `isParallelGroup()`, `countAgents()`
- [x] `frontend/src/components/Terminal.tsx` -- compteur d'agents corrigé via `countAgents()`

**Acceptance Criteria:**
- Given un workflow avec un bloc `parallel:` contenant 2+ agents, when le run démarre le groupe, then tous les agents du groupe passent simultanément en `working` dans le SSE stream
- Given un groupe parallèle où tous réussissent, when le dernier agent complète, then le pipeline avance vers l'étape séquentielle suivante
- Given un groupe parallèle avec un agent mandatory qui échoue, when cet agent passe en error après pool wait, then le run s'arrête
- Given deux agents parallèles écrivant leur output, when le groupe complète, then `session.md` contient les deux outputs sans perte et dans l'ordre YAML
- Given un agent avec `interactive: true` dans un bloc `parallel:`, when YamlService valide le workflow, then une erreur de validation est retournée

## Spec Change Log

## Design Notes

**Structure YAML — bloc explicite :**
Un élément `parallel:` en liste racine regroupe les agents frères, plus explicite qu'un flag `parallel: true` sur chaque agent :
```yaml
agents:
  - id: agent-setup
    engine: claude-code
  - parallel:
    - id: agent-frontend
      engine: claude-code
    - id: agent-backend
      engine: claude-code
  - id: agent-review
    engine: claude-code
```

**Fusion de contexte :**
Chaque agent parallèle écrit dans `run/{id}/tmp/{agentId}.md`. Après le Join, les fichiers sont fusionnés dans `session.md` dans l'ordre de déclaration YAML pour garantir la déterminisme et faciliter le debug.

## Verification

**Commands:**
- `cd backend && php artisan test --filter=ParallelExecution` -- expected: all tests pass
- `cd frontend && npm run type-check` -- expected: 0 errors

## Suggested Review Order

**Moteur d'exécution parallèle**

- Point d'entrée : `executeParallelGroup()` — phases start/wait + error handling + checkpoint
  [`RunService.php:408`](../../backend/app/Services/RunService.php#L408)

- Détection du bloc parallel dans la boucle principale
  [`RunService.php:129`](../../backend/app/Services/RunService.php#L129)

- Garde checkpoint resume pour groupe parallèle
  [`RunService.php:81`](../../backend/app/Services/RunService.php#L81)

**Abstraction asynchrone — drivers**

- Nouveau contrat `startAsync()` — retourne `[InvokedProcess, callable]`
  [`DriverInterface.php:34`](../../backend/app/Drivers/DriverInterface.php#L34)

- ClaudeDriver : `startAsync()` avec `Process::start()` au lieu de `run()`
  [`ClaudeDriver.php:19`](../../backend/app/Drivers/ClaudeDriver.php#L19)

- GeminiDriver : même pattern, inlining de `runGeminiStream`
  [`GeminiDriver.php:15`](../../backend/app/Drivers/GeminiDriver.php#L15)

**Gestion du contexte partagé**

- `writeAgentTempOutput()` — isolation des écriture par fichier temp per-agent
  [`ArtifactService.php:101`](../../backend/app/Services/ArtifactService.php#L101)

- `mergeParallelOutputs()` — fusion dans l'ordre YAML, cleanup des fichiers temp
  [`ArtifactService.php:113`](../../backend/app/Services/ArtifactService.php#L113)

**Validation YAML**

- Branch parallel dans `validate()` — groupe ≥2 agents, appelle `validateAgent`
  [`YamlService.php:105`](../../backend/app/Services/YamlService.php#L105)

- `validateAgent()` — contraintes inParallelGroup (no interactive, loop, sub-workflow)
  [`YamlService.php:130`](../../backend/app/Services/YamlService.php#L130)

**Types et rendu frontend**

- `WorkflowStep = Agent | ParallelGroup`, `isParallelGroup()`, `countAgents()`
  [`workflow.types.ts:1`](../../frontend/src/types/workflow.types.ts#L1)

- Rendu du groupe parallèle avec bordure gauche dans la sidebar
  [`Sidebar.tsx:107`](../../frontend/src/components/Sidebar.tsx#L107)

- Guard `setAgentLiveLog` retiré — plusieurs agents working simultanément
  [`agentStatusStore.ts:39`](../../frontend/src/stores/agentStatusStore.ts#L39)
