---
title: 'Review Node — Boucle de critique et auto-correction'
type: 'feature'
created: '2026-04-16'
status: 'draft'
context: [spec-sub-workflow-node.md]
---

## Intent

**Problème :** Dans un pipeline linéaire, si un agent produit un résultat médiocre ou erroné, les agents suivants travaillent sur une base corrompue, menant à l'échec du run ou à une sortie inutilisable.

**Approche :** Introduire un type de nœud `engine: reviewer`. Ce nœud analyse l'output de l'agent précédent (ou d'un agent spécifique ciblé). S'il détecte des problèmes, il renvoie un signal `retry_previous` avec une critique structurée. Le moteur rembobine l'exécution à l'agent cible en lui injectant cette critique comme instruction supplémentaire.

## Boundaries & Constraints

**Always :**
- Un nœud Reviewer doit définir une `target_agent_id`.
- La critique doit être persistée dans le dossier de run (`agents/{id}-review.md`).
- Le moteur doit limiter le nombre de boucles via un paramètre `max_iterations` (défaut: 2) pour éviter les cycles infinis.
- L'agent cible reçoit la critique dans son prompt d'entrée lors du retry.

**Never :**
- Faire boucler le Reviewer sur lui-même.
- Supprimer les anciens artefacts lors du retry (on versionne les tentatives : `agent_v1.md`, `agent_v2.md`).

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Behavior |
|----------|--------------|-------------------|
| Validation OK | Output conforme | Le Reviewer passe en `done`, le pipeline continue. |
| Échec critique | Erreurs détectées | Le moteur rembobine à l'agent cible, décrémente `max_iterations`. |
| Boucle infinie | `max_iterations` atteint | Le run s'arrête en `error` avec le log des critiques précédentes. |

## Code Map (Impact)

- `backend/app/Services/RunService.php` : Logique de saut d'index dans la boucle d'exécution si `retry_previous` est détecté.
- `backend/app/Services/CheckpointService.php` : Gérer l'effacement partiel ou l'invalidation des checkpoints futurs lors du rembobinage.
- `frontend/src/components/ReviewNode.tsx` : Nouveau composant visuel (losange de décision).
