### Configuration des Workflows YAML

Cette documentation détaille la structure et les options disponibles pour la rédaction des fichiers de workflow YAML dans le système **XuMaestro**, basées sur l'analyse du code source du backend (notamment `YamlService.php` et `RunService.php`).

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
- `interactive` (bool, défaut: false) : Active la possibilité pour l'agent de poser une question à l'utilisateur. Injecte automatiquement l'instruction `waiting_for_input` dans le prompt de l'agent.

#### Configuration du Prompt et des Instructions
- `system_prompt` (string) : Instructions directes définissant le rôle de l'agent (ex: "Tu es un expert en Python").
- `system_prompt_file` (string) : Nom d'un fichier (ex: `dev.md`) situé dans le répertoire `prompts/` contenant les instructions système.
- `steps` (array of strings) : Liste de tâches spécifiques que l'agent doit accomplir. Elles sont ajoutées au contexte de l'agent sous une section `## Task`.

---

### Exécution Parallèle (`parallel:`)

Le bloc `parallel:` permet d'exécuter plusieurs agents simultanément. Le pipeline attend que tous les agents du groupe aient terminé avant de passer à l'étape suivante.

```yaml
agents:
  - id: agent-setup
    engine: claude-code

  - parallel:
    - id: agent-frontend
      engine: claude-code
      system_prompt: "Implémente le composant UI."
    - id: agent-backend
      engine: claude-code
      system_prompt: "Implémente l'endpoint API."

  - id: agent-review
    engine: claude-code
```

#### Fonctionnement

- **Démarrage simultané** : tous les agents du groupe sont lancés en parallèle ; chacun reçoit le même snapshot du contexte partagé au démarrage du groupe.
- **Fusion du contexte** : chaque agent écrit son output dans un fichier temporaire isolé. Une fois le groupe terminé, les outputs sont fusionnés dans `session.md` dans l'ordre de déclaration YAML (déterministe).
- **Timeout indépendant** : chaque agent parallèle a son propre `timeout`.
- **Gestion des échecs** :
    - Si un agent `mandatory: true` échoue, tout le groupe échoue et le run s'arrête.
    - Si un agent non-mandatory échoue, le groupe continue et un avertissement est émis dans le flux SSE.
- **Checkpoint** : le groupe entier est traité comme une étape atomique. Le checkpoint n'est écrit qu'après la complétion de tous les agents du groupe.

#### Contraintes

- `interactive: true` est interdit sur un agent dans un bloc `parallel:`.
- `loop:` est interdit sur un agent parallèle.
- Les blocs `parallel:` imbriqués ne sont pas supportés.
- Un groupe `parallel:` doit contenir au minimum 2 agents.

---

### Git Checkpoints (Auto-Commit IA)

Cette fonctionnalité permet au système d'effectuer automatiquement un commit Git après chaque exécution réussie d'un agent. Le message de commit est généré par une IA en analysant les modifications (`git diff`) et le résultat de l'agent.

#### Configuration Globale
Active les checkpoints pour tous les agents du workflow.
```yaml
name: "Mon Workflow"
project_path: "/app/mon-projet"
git_checkpoints:
  enabled: true
  engine: "claude-code" # Optionnel, défaut: engine de l'agent
  prompt: "Utilise la convention gitmoji." # Optionnel, défaut: gitmoji standard
agents:
  # ...
```

#### Configuration par Agent
La configuration de l'agent surcharge la configuration globale.
```yaml
agents:
  - id: agent-1
    git_checkpoint: true # Utilise les réglages globaux ou par défaut
    # OU configuration détaillée
    git_checkpoint:
      enabled: true
      engine: "gemini-cli"
      prompt: "Sois très bref."
```

#### Fonctionnement
1. **Staging** : Exécution automatique de `git add .` dans le `project_path`.
2. **Analyse** : Si des changements sont détectés, le système appelle l'IA pour générer un message.
3. **Commit** : Exécution de `git commit -m "[message_généré]"`.
4. **Feedback** : Un message d'information apparaît dans l'interface avec le contenu du commit.

*Note : Le `project_path` doit être un dépôt Git valide pour que cette fonctionnalité s'active.*

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

### Système de Boucle (`loop`)
Il est possible de faire boucler un agent ou un sous-workflow sur une liste de fichiers (pattern glob). Chaque itération relance l'agent avec un contexte "propre" (isolé des itérations précédentes).

```yaml
  - id: processeur-de-tickets
    engine: gemini-cli
    loop:
      over: "tickets/*.md"  # Pattern glob relatif au project_path
      as: "ticket"          # Nom de la variable à injecter
    system_prompt: "Analyse le ticket : {{ ticket }}"
    steps:
      - "Lire le contenu de {{ ticket }}"
      - "Générer un résumé"
```

#### Fonctionnement du Loop
- **`over`** : Un pattern glob (ex: `src/**/*.ts`, `docs/*.md`). Le système résout la liste des fichiers correspondants au début de l'exécution de l'agent.
- **`as`** : Le nom de la variable que vous pouvez utiliser dans `system_prompt` ou `steps` via la syntaxe `{{ variable }}`.
- **Isolation du contexte** : Pour chaque itération, l'agent ne voit que le contexte accumulé *avant* le début de la boucle. Il ne voit pas les sorties des itérations précédentes de la même boucle.
- **Continuité** : Une fois la boucle terminée, TOUTES les sorties de toutes les itérations sont ajoutées au contexte pour les agents suivants.
- **IDs d'artefacts** : Les fichiers d'artefacts sont nommés `agents/{id}--{index}.md` (ex: `processeur-de-tickets--1.md`).
- **Sub-workflow** : Si un `sub-workflow` boucle, c'est toute la séquence d'agents du sous-workflow qui est répétée pour chaque item.

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

#### Statut spécial : Interaction Utilisateur (`waiting_for_input`)
Un agent peut interrompre l'exécution pour poser une question à l'utilisateur. L'exécution reprend automatiquement une fois la réponse soumise.

```json
{
  "step": "Question posée à l'utilisateur",
  "status": "waiting_for_input",
  "question": "Quel est le nom du client final ?",
  "output": "",
  "next_action": null,
  "errors": []
}
```

- **`question`** (string, requis si `status: waiting_for_input`) : La question affichée à l'utilisateur dans l'interface.
- L'agent affiche un badge violet avec une zone de saisie sous sa carte. La réponse est injectée dans le contexte partagé pour les agents suivants.
- **Timeout :** Si l'utilisateur ne répond pas dans les **15 minutes**, le run passe en erreur (relançable depuis le checkpoint).

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
