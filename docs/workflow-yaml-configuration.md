### Configuration des Workflows YAML

Cette documentation détaille la structure et les options disponibles pour la rédaction des fichiers de workflow YAML dans le système **xu-workflow**, basées sur l'analyse du code source du backend (notamment `YamlService.php` et `RunService.php`).

---

### Structure Globale du Fichier
Un fichier de workflow est un document YAML définissant un nom, un chemin de projet et une liste d'agents à exécuter séquentiellement.

```yaml
name: "Nom du Workflow"
project_path: "/chemin/vers/le/projet"
agents:
  - id: agent_1
    engine: gemini-cli
    # ... autres options
```

#### Champs de Niveau Supérieur
- `name` (string, requis) : Nom descriptif du workflow.
- `project_path` (string, requis) : Chemin absolu du répertoire où les agents s'exécuteront (répertoire de travail).
- `agents` (array, requis) : Liste ordonnée des agents à exécuter.

---

### Configuration des Agents
Chaque agent dans la liste `agents` accepte les paramètres suivants :

#### Paramètres Requis
- `id` (string) : Identifiant unique de l'agent dans le workflow. Utilisé pour les logs, les checkpoints et le nommage des fichiers d'artefacts.
- `engine` (string) : Moteur d'exécution. Valeurs possibles :
    - `gemini-cli` : Utilise l'outil CLI `gemini`.
    - `claude-code` : Utilise l'outil CLI `claude`.
    - `sub-workflow` : Permet l'appel d'un autre fichier de workflow.

#### Options d'Exécution
- `timeout` (int, défaut: 120) : Temps maximum d'exécution en secondes avant l'interruption de l'agent.
- `mandatory` (bool, défaut: false) : Si `true`, l'agent sera relancé automatiquement en cas d'échec (erreur CLI ou timeout).
- `max_retries` (int, défaut: 0) : Nombre de tentatives supplémentaires en cas d'échec (actif uniquement si `mandatory: true`).
- `skippable` (bool, défaut: false) : Permet à l'agent précédent de sauter cet agent via l'instruction `next_action: "skip_next"` dans sa réponse JSON.

#### Configuration du Prompt et des Instructions
- `system_prompt` (string) : Instructions directes définissant le rôle de l'agent (ex: "Tu es un expert en Python").
- `system_prompt_file` (string) : Nom d'un fichier (ex: `dev.md`) situé dans le répertoire `prompts/` contenant les instructions système.
- `steps` (array of strings) : Liste de tâches spécifiques que l'agent doit accomplir. Elles sont ajoutées au contexte de l'agent sous une section `## Task`.

---

### Moteur Spécial : `sub-workflow`
Le moteur `sub-workflow` permet de composer des workflows complexes en appelant des fichiers YAML existants.

```yaml
- id: mon-sous-workflow
  engine: sub-workflow
  workflow_file: simple-workflow.yaml
  mandatory: true
```

- **Paramètre spécifique :** `workflow_file` (string, requis) : Nom du fichier YAML à inclure (doit être présent dans le dossier des workflows).
- **Comportement :**
    - Les agents du sous-workflow sont exécutés l'un après l'autre.
    - Les IDs des agents du sous-workflow sont préfixés par l'ID de l'appelant (ex: `mon-sous-workflow--dev`).
    - **Note :** La récursion (un sous-workflow appelant un autre sous-workflow) n'est pas supportée.

---

### Format de Réponse Attendu des Agents
Bien que cela concerne l'implémentation de l'agent lui-même, le système impose un format de sortie JSON pour assurer la continuité du workflow. Le système injecte automatiquement cette instruction de formatage dans le prompt de l'agent :

```json
{
  "step": "<brief description of what you did>",
  "status": "done",
  "output": "<your full response>",
  "next_action": "skip_next" | null,
  "errors": []
}
```

---

### Exemples de Fichiers

#### Workflow Simple (`simple-workflow.yaml`)
```yaml
name: "Analyse et Développement"
project_path: "/app/mon-projet"
agents:
  - id: pm
    engine: gemini-cli
    system_prompt: "Analyse la demande et reformule."
    steps:
      - "Analyser le ticket #123"

  - id: dev
    engine: claude-code
    skippable: true
    mandatory: true
    max_retries: 2
    system_prompt_file: "dev-role.md"
```

#### Workflow avec Inclusion (`sub-workflow.yaml`)
```yaml
name: "Pipeline Complet"
project_path: "/app/mon-projet"
agents:
  - id: traducteur
    engine: gemini-cli
    system_prompt: "Traduis en anglais."
    
  - id: execution-dev
    engine: sub-workflow
    workflow_file: simple-workflow.yaml
```
