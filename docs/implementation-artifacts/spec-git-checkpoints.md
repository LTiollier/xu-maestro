# Spécification : Git Checkpoints (Auto-Commit IA)

Cette fonctionnalité permet au système **XuMaestro** d'effectuer automatiquement un commit Git après chaque exécution réussie d'un agent. Le message de commit est généré par une IA (Gemini ou Claude) en fonction des modifications effectuées par l'agent.

## 1. Configuration YAML

L'utilisateur peut activer et configurer les checkpoints Git au niveau global du workflow ou au niveau de chaque agent. La configuration de l'agent surcharge la configuration globale.

### Configuration Globale
```yaml
name: "Mon Workflow"
project_path: "/app/mon-projet"
git_checkpoints:
  enabled: true
  engine: "claude-code" # Facultatif, défaut: engine de l'agent
  prompt: "Utilise la convention gitmoji." # Facultatif, défaut: gitmoji standard
agents:
  - id: agent-1
    # ...
```

### Configuration par Agent
```yaml
agents:
  - id: agent-1
    engine: "gemini-cli"
    git_checkpoint: true # Utilise les réglages par défaut
    # OU configuration détaillée
    git_checkpoint:
      enabled: true
      engine: "claude-code"
      prompt: "Sois très bref."
```

## 2. Prompt par défaut (Gitmoji)

Le prompt par défaut utilisé pour générer le message de commit sera :
> "Génère un message de commit Git concis en utilisant la convention Gitmoji (ex: :sparkles: add feature). Analyse les modifications effectuées et résume l'intention. Ne réponds QUE le message de commit, sans texte avant ou après."

## 3. Workflow d'exécution (Backend)

Lorsqu'un agent termine son exécution avec succès (`status: done`) :

1.  **Vérification de l'activation :** Le `RunService` vérifie si `git_checkpoint` est activé pour cet agent.
2.  **Préparation (Staging) :** Exécution de `git add .` dans le `project_path`.
3.  **Vérification des changements :** Si `git diff --cached --quiet` n'indique aucun changement, le commit est ignoré (pas de commit vide).
4.  **Génération du message :**
    *   Le système récupère le `output` de l'agent et le `system_prompt`.
    *   Il appelle l'engine configuré (ou celui de l'agent par défaut) avec le prompt de génération de commit.
    *   Le contexte envoyé à l'IA de génération inclut le diff (`git diff --cached`) pour plus de précision.
5.  **Commit :** Exécution de `git commit -m "[message_généré]"`.
6.  **Logs :** Un événement `AgentBubble` de type `info` est émis pour informer l'utilisateur du commit effectué (ex: "📦 Commit : :sparkles: add login logic").

## 4. Implémentations techniques

### Nouveau Service : `GitService`
Responsable des interactions avec l'exécutable Git.
*   `add(string $path): void`
*   `hasChanges(string $path): bool`
*   `commit(string $path, string $message): void`
*   `generateCommitMessage(string $path, string $agentOutput, array $config): string`

### Modifications `RunService`
*   Injection de `GitService`.
*   Appel à la logique de commit à la fin de la boucle `executeAgents`.

### Considérations de sécurité
*   Le `project_path` doit être un dépôt Git valide. Si ce n'est pas le cas, le système doit soit ignorer silencieusement, soit émettre un warning, mais ne pas bloquer le run.
*   Les commandes Git sont exécutées via `Process` Laravel.

## 5. Exemple de Prompt de génération
Le prompt envoyé à l'IA pour générer le message ressemblera à ceci :
```text
Tu es un expert Git. Génère un message de commit pour les modifications suivantes.
PROMPT DE STYLE : {{ prompt_configuré }}

MODIFICATIONS (DIFF) :
{{ git_diff }}

RÉSULTAT DE L'AGENT :
{{ agent_output }}

Réponds uniquement le message de commit.
```
