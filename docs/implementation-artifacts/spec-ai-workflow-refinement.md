---
title: 'Iterative AI Workflow Refinement'
type: 'feature'
created: '2026-04-23'
status: 'draft'
context: []
---

## Intent

**Problème :** La génération de workflow par IA est actuellement un processus "one-shot". Si le résultat initial n'est pas parfait, l'utilisateur doit soit tout recommencer avec un nouveau brief, soit éditer manuellement le YAML complexe.

**Approche :** Transformer le Workflow Wizard en une interface itérative. Une fois le premier jet généré, l'utilisateur peut discuter avec l'IA pour affiner le workflow (ex: "Ajoute un agent de sécurité", "Change l'engine de l'agent de recherche pour Claude", "Simplifie les étapes de l'agent de QA").

## Boundaries & Constraints

**Always:**
- Conserver la possibilité d'éditer manuellement le YAML à tout moment.
- Valider chaque itération du YAML via `YamlService::validate()`.
- Maintenir le schéma strict du workflow.
- Inclure le YAML actuel dans le prompt de raffinement pour que l'IA ait le contexte.

**Never:**
- Perdre l'historique des modifications (l'utilisateur doit pouvoir revenir en arrière si possible - *optionnel pour v1*).
- Remplacer le YAML par quelque chose de structurellement invalide sans prévenir.

## User Journey

1. L'utilisateur ouvre le `WorkflowWizard`.
2. Step 1 : Saisie du brief initial (ex: "Pipeline de migration React").
3. L'IA génère un premier YAML.
4. Step 2 : L'utilisateur voit le YAML et le diagramme (via les badges d'agents).
5. L'utilisateur saisit un message de raffinement : "Ajoute un agent de documentation après la migration".
6. L'utilisateur clique sur "Affiner".
7. L'IA renvoie le YAML mis à jour.
8. L'utilisateur répète l'opération ou enregistre le workflow.

## I/O & Edge-Case Matrix

| Scenario | Input | Behavior |
|----------|-------|----------|
| Premier brief | `brief` | Génération standard (comportement actuel). |
| Raffinement | `current_yaml` + `brief` | L'IA reçoit le YAML actuel + les instructions et renvoie le YAML modifié. |
| YAML invalide | YAML édité manuellement + `brief` | L'IA tente de corriger ou de modifier le YAML fourni même s'il a des erreurs (le driver gère le strip des fences). |
| Erreur backend | Timeout ou erreur IA | Message d'erreur, le YAML actuel est conservé. |

## Code Map

- `backend/app/Http/Controllers/WorkflowController.php` :
    - Modifier `generate()` pour accepter `current_yaml` optionnel.
- `backend/app/Services/WorkflowScaffolderService.php` :
    - Modifier `scaffold()` pour inclure le contexte du YAML actuel dans le prompt si présent.
- `frontend/src/components/WorkflowWizard.tsx` :
    - Ajouter un champ input "Affiner avec l'IA" dans le Step 2.
    - Gérer l'état de chargement spécifique au raffinement.

## Tasks & Acceptance

**Execution:**
- [ ] **Backend: Refactor `WorkflowController@generate`**
    - Accepter `current_yaml` (nullable string) dans `GenerateWorkflowRequest`.
- [ ] **Backend: Update `WorkflowScaffolderService`**
    - Adapter le system prompt pour le mode "modification" si `current_yaml` est fourni.
    - S'assurer que l'IA comprend qu'elle doit retourner le YAML *complet* mis à jour.
- [ ] **Frontend: UI Updates in `WorkflowWizard`**
    - Ajouter un input field sous la textarea YAML (ou à côté) pour le raffinement.
    - Ajouter un bouton "Affiner".
    - Mettre à jour `handleGenerate` pour envoyer le `yamlContent` actuel s'il existe.
- [ ] **Validation**
    - Vérifier que l'IA respecte les instructions de modification tout en gardant la structure globale.
    - Vérifier que les erreurs YAML sont gérées gracieusement (affichage du brut pour correction).

**Acceptance Criteria:**
- Given un YAML déjà généré dans le wizard, when l'utilisateur saisit "Ajoute un agent X" et clique sur Affiner, then le YAML est mis à jour avec l'agent X inclus.
- Given un YAML modifié manuellement par l'utilisateur, when l'utilisateur demande un raffinement, then l'IA prend en compte les modifications manuelles dans sa réponse.
- Given un raffinement en cours, when l'utilisateur ferme le dialog, then la requête est annulée.
