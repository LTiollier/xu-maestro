---
title: 'Nœud de sous-workflow dans un workflow parent'
type: 'feature'
created: '2026-04-14'
status: 'done'
baseline_commit: '536db41d39e01906c494e7418fd3a36d8309fcef'
context: []
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** Un workflow ne peut contenir que des nœuds agents CLI. Il est impossible de composer des workflows en appelant un autre workflow comme nœud, ce qui force la duplication de séquences d'agents.

**Approach:** Ajouter un type de nœud `engine: sub-workflow` dans le YAML. Lors de l'exécution, le moteur charge le workflow référencé et exécute ses agents inline dans le même dossier de run, avec des IDs préfixés. Visuellement, le nœud s'affiche en cercle dans le diagramme.

## Boundaries & Constraints

**Always:**
- Les agents du sous-workflow écrivent dans le même dossier de run que le parent (`agents/{node-id}--{agent-id}.md`)
- La validation YAML doit vérifier que `workflow_file` existe dans `workflows/` et est un YAML valide au chargement
- `workflow_file` est un nom de fichier relatif au dossier `workflows/`
- Les flags `mandatory` et `skippable` du nœud sub-workflow s'appliquent au niveau du nœud : l'échec de n'importe quel agent `mandatory` du sous-workflow = échec du nœud parent

**Ask First:**
- Si le sous-workflow référencé contient lui-même un nœud `sub-workflow` (imbrication récursive)
- Si les événements SSE doivent exposer le statut des agents individuels du sous-workflow dans le frontend (plutôt que juste le statut du nœud)

**Never:**
- Créer un dossier de run séparé pour le sous-workflow
- Afficher les agents individuels du sous-workflow dans le diagramme du workflow parent
- Modifier la structure du dossier de run existant (`runs/YYYY-MM-DD-HHmmss/`)

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| Happy path | Nœud `engine: sub-workflow`, `workflow_file: other.yaml` valide | Agents du sous-workflow exécutés inline ; sorties dans `agents/{node-id}--{agent-id}.md` | N/A |
| Fichier manquant | `workflow_file: nonexistent.yaml` | Erreur de validation au chargement | Message d'erreur retourné par l'API, affiché dans l'UI |
| Agent sous-workflow échoue | Agent `mandatory: true` du sous-workflow échoue | Nœud sub-workflow marqué `error` ; parent suit la logique `mandatory` habituelle | Propagation normale d'erreur |

</frozen-after-approval>

## Code Map

- `backend/app/Services/YamlService.php` -- validation YAML : accepter `engine: sub-workflow` avec `workflow_file` obligatoire
- `backend/app/Services/RunService.php` -- `executeAgents()` : détecter et exécuter inline les nœuds sub-workflow
- `backend/app/Services/ArtifactService.php` -- création fichiers agents ; réutiliser avec ID préfixé sans changement structurel
- `frontend/src/components/AgentDiagram.tsx` -- mapping agents → nœuds React Flow ; enregistrer le nouveau type `workflowNode`
- `frontend/src/components/AgentNode.tsx` -- référence visuelle pour le nouveau composant cercle
- `frontend/src/components/SubworkflowNode.tsx` -- nouveau composant (à créer) : cercle avec indicateur de statut

## Tasks & Acceptance

**Execution:**
- [x] `backend/app/Services/YamlService.php` -- Accepter `engine: sub-workflow` dans la validation ; rendre `workflow_file` obligatoire ; vérifier que le fichier existe dans `workflows/` et est un YAML valide -- Évite les runs qui échouent silencieusement sur un workflow mal référencé
- [x] `backend/app/Services/RunService.php` -- Dans `executeAgents()`, détecter `engine === 'sub-workflow'`, charger le sous-workflow YAML, exécuter ses agents avec `{node-id}--{agent-id}` comme ID et le même `runPath` ; émettre les événements SSE du nœud parent (`working` → `done`/`error`) -- Permet l'exécution inline dans le même run
- [x] `frontend/src/components/SubworkflowNode.tsx` -- Créer un composant nœud circulaire avec indicateur de statut (idle/working/done/error/skipped) et affichage du nom -- Différencie visuellement les nœuds sub-workflow
- [x] `frontend/src/components/AgentDiagram.tsx` -- Enregistrer `workflowNode` dans `nodeTypes` ; assigner `type: 'workflowNode'` aux agents dont `engine === 'sub-workflow'` -- Connecte le composant au diagramme

**Acceptance Criteria:**
- Given un workflow avec un nœud `engine: sub-workflow`, when le workflow est chargé, then le nœud apparaît en cercle dans le diagramme
- Given un run avec un nœud sub-workflow, when le nœud s'exécute, then les sorties des agents du sous-workflow sont dans `agents/{node-id}--{agent-id}.md` dans le dossier de run parent
- Given un nœud sub-workflow avec `workflow_file` inexistant, when le workflow est validé, then une erreur de validation est retournée

## Spec Change Log

## Design Notes

Les IDs des agents du sous-workflow suivent le pattern `{node-id}--{agent-id}` (double tiret) pour éviter toute collision dans le dossier de run (ex : `call-subwf--pm`, `call-subwf--dev`).

Exemple de YAML :
```yaml
- id: call-validation-wf
  engine: sub-workflow
  workflow_file: validation-workflow.yaml
  mandatory: true
```

Le nœud sub-workflow est une boîte noire du point de vue du diagramme parent : un seul nœud visible (cercle) qui cache N agents internes.

## Suggested Review Order

**Validation YAML (point d'entrée)**

- Nouvelles règles de validation pour `engine: sub-workflow` — workflow_file requis + is_file
  [`YamlService.php:94`](../../backend/app/Services/YamlService.php#L94)

**Exécution inline du sous-workflow**

- Point d'entrée : détection du nœud sub-workflow dans la boucle principale
  [`RunService.php:136`](../../backend/app/Services/RunService.php#L136)

- Méthode centrale : chargement, recursion guard, boucle sur les agents du sous-workflow
  [`RunService.php:295`](../../backend/app/Services/RunService.php#L295)

- Logique de propagation d'erreur : seul `$subIsMandatory` détermine si un sub-agent fait échouer le nœud
  [`RunService.php:350`](../../backend/app/Services/RunService.php#L350)

**Composant visuel**

- Nouveau nœud circulaire avec indicateurs de statut (idle/working/done/error/skipped)
  [`SubworkflowNode.tsx:1`](../../frontend/src/components/SubworkflowNode.tsx#L1)

- Enregistrement du type `workflowNode` et assignation conditionnelle dans le diagramme
  [`AgentDiagram.tsx:18`](../../frontend/src/components/AgentDiagram.tsx#L18)

## Verification

**Commands:**
- `cd backend && php artisan test --filter=SubWorkflow` -- expected: all tests pass
- `cd frontend && npx tsc --noEmit` -- expected: no type errors

**Manual checks:**
- Créer un workflow avec un nœud sub-workflow, lancer un run, vérifier que `agents/{node-id}--*.md` sont créés dans le bon dossier de run
- Vérifier que le nœud sub-workflow apparaît en cercle dans le diagramme parent
