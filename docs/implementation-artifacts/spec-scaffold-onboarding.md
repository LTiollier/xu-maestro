---
title: 'Scaffold Onboarding — Générateur de Workflow IA'
type: 'feature'
created: '2026-04-16'
status: 'draft'
context: []
---

## Intent

**Problème :** Écrire un YAML complexe à la main est fastidieux et sujet aux erreurs de syntaxe ou de logique d'enchaînement des agents.

**Approche :** Créer une vue "Architecte" où l'utilisateur décrit son objectif en langage naturel. Un agent spécialisé (Scaffolder) génère le YAML optimal, propose une équipe d'agents, et permet de prévisualiser le diagramme avant d'enregistrer le fichier dans `workflows/`.

## Boundaries & Constraints

**Always :**
- L'agent doit utiliser un schéma YAML strict.
- Proposer des noms d'agents et des system prompts pertinents par rapport au brief.
- Permettre l'édition manuelle du YAML généré avant sauvegarde.
- Vérifier la validité du YAML généré via `YamlService` avant de proposer la sauvegarde.

**Never :**
- Écraser un fichier existant sans confirmation explicite.

## User Journey

1. L'utilisateur clique sur "Nouveau Workflow" dans la topbar.
2. Une interface de chat s'ouvre. L'Architecte demande : "Quelle équipe d'agents souhaitez-vous constituer ?".
3. L'utilisateur : "Je veux une équipe pour migrer mon app de React vers Next.js".
4. L'Architecte génère une équipe : Migration Auditor -> Code Transpiler -> QA Specialist.
5. Le diagramme de prévisualisation s'affiche instantanément.
6. L'utilisateur nomme le workflow `react-to-next.yaml` et clique sur "Créer".

## Code Map (Impact)

- `backend/app/Http/Controllers/WorkflowController.php` : Nouvel endpoint `POST /api/workflows/generate`.
- `frontend/src/components/WorkflowWizard.tsx` : Nouvelle interface modale ou vue dédiée.
- `backend/app/Drivers/GeminiDriver.php` : Utiliser Gemini (plus rapide pour le scaffolding) pour générer le YAML.
