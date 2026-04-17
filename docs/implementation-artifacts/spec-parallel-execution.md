---
title: 'Exécution Parallèle — Fan-out / Fan-in'
type: 'feature'
created: '2026-04-16'
status: 'draft'
context: []
---

## Intent

**Problème :** Certains agents n'ont pas de dépendances directes entre eux (ex: Frontend et Backend). Les exécuter en séquence double artificiellement le temps du run.

**Approche :** Permettre de définir des groupes d'agents dans le YAML via une clé `parallel: true`. Le moteur Laravel lance ces agents simultanément (multi-process). Un nœud de synchronisation (Wait/Join) bloque le pipeline jusqu'à ce que tous les agents du groupe parallèle soient `done`.

## Boundaries & Constraints

**Always :**
- Chaque agent parallèle a son propre timeout indépendant.
- Les événements SSE doivent pouvoir envoyer des statuts `working` simultanés pour plusieurs agents.
- Les résultats de chaque agent doivent être fusionnés dans le contexte partagé sans collision.

**Never :**
- Autoriser l'interaction humaine (`waiting_for_input`) simultanée sur plusieurs agents (risque de confusion UI).

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Behavior |
|----------|--------------|-------------------|
| Succès groupé | 3 agents finissent | Le pipeline attend le dernier puis passe à l'agent suivant. |
| Un agent échoue | 1 error / 2 success | Si l'agent est `mandatory`, tout le groupe est considéré en erreur. |
| Collision contexte | Écriture simultanée | Utilisation de verrous (locks) ou de fichiers temporaires par agent avant fusion. |

## Code Map (Impact)

- `backend/app/Services/RunService.php` : Utilisation de `Process::pool()` de Laravel pour l'exécution concurrente.
- `frontend/src/components/AgentDiagram.tsx` : Support des arêtes multiples partant d'un seul nœud (Fan-out) et arrivant à un nœud (Fan-in).
- `frontend/src/stores/agentStatusStore.ts` : Supporter plusieurs agents dans l'état `working` simultanément.
