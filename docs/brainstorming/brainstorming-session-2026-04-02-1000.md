---
stepsCompleted: [1, 2, 3, 4]
inputDocuments: []
session_topic: 'Orchestrateur d''agents IA multi-rôles via CLI (Claude Code / Gemini CLI)'
session_goals: 'Fonctionnalités, architecture technique, UX gamifiée, différenciation marché'
selected_approach: 'ai-recommended'
techniques_used: ['What If Scenarios', 'Dream Fusion Laboratory', 'SCAMPER Method']
ideas_generated: 55
context_file: ''
session_active: false
workflow_completed: true
---

# Brainstorming Session — Orchestrateur d'agents IA CLI

**Facilitateur :** Léo
**Date :** 2026-04-02

---

## Session Overview

**Topic :** Orchestrateur d'agents IA multi-rôles via CLI (Claude Code / Gemini CLI)
**Goals :** Fonctionnalités, architecture technique, UX gamifiée, différenciation marché

### Contexte

Application de gestion de workflow d'IA multi-agents avec :
- Configuration YAML des rôles (PM, devs Laravel/React/Docker, QA, Testeur...)
- Pipeline agent → agent avec routage intelligent
- Interface visuelle gamifiée (personnages pixel art, pop-ups BD, couleurs pastels)
- Stack : Next.js / React / TypeScript + Laravel (localhost)
- **Différenciateur clé :** gratuit via abonnements CLI (Claude Code + Gemini CLI), sans API payantes

---

## Technique Selection

**Approche :** Recommandations IA
**Techniques :** What If Scenarios → Dream Fusion Laboratory → SCAMPER Method

---

## Inventaire complet des idées

### Thème 1 — Moteur de Workflow

**[Workflow #1] YAML Ordered Task Pipeline**
_Concept :_ Chaque agent a une liste de tâches ordonnées dans le YAML. L'agent exécute séquentiellement, sans sauter d'étape.
_Novelty :_ Contrairement aux orchestrateurs classiques qui laissent l'IA décider, le développeur contrôle précisément le workflow — reproductible, auditable, prévisible.

**[Workflow #2] Mandatory Step Flag**
_Concept :_ `mandatory: true` sur une étape — si elle échoue, l'agent reboucle automatiquement avant de continuer.
_Novelty :_ Garantit une qualité minimale sans intervention humaine, le workflow devient auto-correctif sur ses propres étapes.

**[Workflow #3] Human Review Gate**
_Concept :_ `human_review: true` optionnel par étape. L'interface affiche une bulle "En attente de validation" avec boutons Approuver/Modifier. Si absent (défaut), le workflow est 100% autonome.
_Novelty :_ Même système, deux modes d'usage — full autonome ou semi-supervisé selon les cas critiques.

**[Workflow #4] Parallel Agent Execution**
_Concept :_ `parallel: [laravel-dev, nextjs-dev]` dans le YAML. Les agents s'exécutent simultanément avec contextes séparés, le workflow reprend quand tous ont terminé (join).
_Novelty :_ Multiplie la vitesse d'exécution sur les projets fullstack. Deux personnages travaillent en même temps dans l'open space.

**[Workflow #5] Step-Level Checkpoint Resume**
_Concept :_ Chaque étape complétée écrit un checkpoint dans le `.md`. En cas d'erreur à l'étape 3, le retry charge le contexte jusqu'au checkpoint 2 et relance uniquement depuis l'étape 3.
_Novelty :_ Économise du temps et des tokens CLI. Le `.md` devient aussi un système de recovery.

**[Workflow #6] PM as Smart Router**
_Concept :_ Le premier nœud reçoit le prompt brut et décide lui-même du routing — il analyse la demande et route vers le bon agent ou la bonne branche parallèle.
_Novelty :_ Le YAML définit les agents disponibles, mais l'IA PM orchestre dynamiquement selon la nature de la tâche.

**[Workflow #7] AI-Generated YAML via Hidden Pre-prompt**
_Concept :_ Un pre-prompt système caché contient la doc complète du schema YAML. L'utilisateur décrit son équipe en langage naturel, l'IA génère le YAML complet.
_Novelty :_ Zéro courbe d'apprentissage sur la syntaxe — l'onboarding devient conversationnel.

**[Workflow #8] Named Workflow Files**
_Concept :_ Chaque YAML a un `name` en première ligne. Le selector liste tous les fichiers du dossier `workflows/` au démarrage.
_Novelty :_ Organiser ses workflows = organiser ses fichiers. Pas de base de données pour ça.

**[Workflow #9] Per-Node AI Assignment**
_Concept :_ `engine: claude-code` ou `engine: gemini-cli` par agent. Hybridation native des deux CLIs gratuits.
_Novelty :_ Exploite plusieurs abonnements en parallèle — si Claude Code est en rate limit, Gemini prend le relais sur d'autres nœuds.

**[Workflow #10] Project Path in YAML**
_Concept :_ `project_path` racine dans le YAML. Laravel spawne chaque CLI depuis ce répertoire — les agents travaillent directement dans le vrai projet.
_Novelty :_ Git, IDE et l'app coexistent sur les mêmes fichiers sans friction.

**[Workflow #11] Per-Agent System Prompt**
_Concept :_ `system_prompt` ou `system_prompt_file: prompts/agent.md` par agent — instructions de base, persona, conventions, skills à activer.
_Novelty :_ Tout est déclaratif dans le YAML. Séparation nette entre config workflow et instructions métier.

**[Workflow #12] Review Node Type**
_Concept :_ `type: review` — agent secondaire qui reçoit l'output d'un agent précédent, génère des commentaires JSON, les append au `.md`. Peut forcer un retry de l'agent source.
_Novelty :_ Validation IA-sur-IA avant le QA. Exception contrôlée au flux unidirectionnel, déclarée explicitement dans le YAML.

**[Workflow #13] Cross-Agent Context Injection**
_Concept :_ L'agent review reçoit l'output JSON de l'agent reviewé en contexte. Il lit le code produit, laisse des commentaires, peut déclencher un retry.
_Novelty :_ Un agent peut forcer un autre agent en amont à retravailler — sans intervention humaine.

**[Workflow #14] Per-Agent Per-Project Memory File**
_Concept :_ Dossier `memory/` dans le `project_path` avec un `.md` par agent, append-only, injecté dans le `system_prompt` au démarrage du run.
_Novelty :_ La qualité des agents s'améliore run après run. La mémoire est transparente, lisible, versionnable avec git.

**[Workflow #15] Archiviste Agent**
_Concept :_ Agent placé en fin de workflow. Lit le `.md` de session, extrait les décisions clés et conventions, les append aux fichiers mémoire des agents concernés.
_Novelty :_ La gestion de la mémoire est elle-même déléguée à une IA — zéro effort humain. Le cycle devient auto-améliorant.

**[Workflow #16] Cross-Agent Memory Sharing**
_Concept :_ `reads_memory: [qa, pm]` — un agent reçoit en contexte non seulement sa propre mémoire mais aussi celle des agents spécifiés.
_Novelty :_ Intelligence collective entre agents sur la durée d'un projet. Chaque run profite des apprentissages de tous.

**[Workflow #17] Bug Loop Auto-correctif**
_Concept :_ Si le Testing agent détecte un bug, il déclenche un mini-cycle — renvoie au dev concerné, le dev corrige, le DevOps redéploie, le Testing revalide. `max_retries: 3` évite les boucles infinies.
_Novelty :_ L'app s'auto-corrige sur des bugs de surface sans intervention humaine.

**[Workflow #20] Documentaliste Agent**
_Concept :_ Agent en fin de workflow — lit le code produit + `.md` de run + mémoires et génère README de changements, changelog daté, doc API dans le dossier run.
_Novelty :_ Chaque run est un artefact complet : code + trace d'exécution + documentation. Transparence totale.

**[Workflow #21] External System Prompt File**
_Concept :_ `system_prompt_file: prompts/laravel-dev.md` remplace le bloc texte inline. Fichier long, richement formaté, versionné séparément, réutilisable entre workflows.
_Novelty :_ Un expert métier peut maintenir son propre fichier prompt sans toucher au YAML.

**[Workflow #22] Archiviste & Documentaliste as Regular End Nodes**
_Concept :_ Pas de type spécial — ce sont des agents normaux placés en fin de workflow. Leur position dans la séquence est leur déclenchement.
_Novelty :_ Le moteur reste uniforme — tout est un agent avec des tâches. Zéro logique spéciale à coder.

**[Workflow #23] Task Control Flags**
_Concept :_ Par tâche : `mandatory: true`, `timeout: 120` (secondes), `max_retries: 3`. Le moteur gère retry automatique et coupe le process CLI bloqué.
_Novelty :_ Trois paramètres couvrent 95% des cas de robustesse — simple à écrire, puissant à l'exécution.

**[Workflow #24] Multi-YAML Workflow Library**
_Concept :_ Dossier `workflows/` avec autant de YAML que voulu — `feature-dev.yaml`, `bugfix.yaml`, `content-writing.yaml`... Le selector les liste tous.
_Novelty :_ L'app devient plus utile à mesure que l'utilisateur construit sa bibliothèque de workflows.

---

### Thème 2 — Architecture Technique

**[Architecture #1] Normalized JSON Output Contract**
_Concept :_ Chaque agent est prompté pour retourner `{ step, status, output, next_action, errors }`. Next.js parse ce JSON pour piloter l'UI et décider du nœud suivant.
_Novelty :_ Le CLI devient une boîte noire normalisée — peu importe si c'est Claude Code ou Gemini CLI derrière, le contrat de sortie est identique.

**[Architecture #2] Cycle Context File (.md)**
_Concept :_ Pour chaque run, un `.md` append-only est créé et mis à jour à chaque étape. Chaque agent reçoit ce fichier en contexte — il sait ce qui s'est passé avant lui.
_Novelty :_ Résout le problème de mémoire inter-agents sans base de données. Le fichier `.md` est la mémoire partagée du cycle.

**[Architecture #3] Dual Channel Communication**
_Concept :_ Deux flux séparés — (1) `.md` append-only comme journal de bord en sidebar, (2) bulles temps réel pour l'état courant. Chacun a son propre mécanisme de transport.
_Novelty :_ Séparer log persistant et événements éphémères évite de polluer le contexte des agents avec du bruit UI.

**[Architecture #4] MCP Bubble Tool**
_Concept :_ Serveur MCP custom expose un tool `send_bubble(message, step, agent_id)`. Claude Code l'appelle → WebSocket → React frontend.
_Novelty :_ L'IA pilote son propre affichage — pas de parsing de sortie CLI fragile.

**[Architecture #5] Artisan SSE Command**
_Concept :_ `php artisan bubble:send --agent=laravel --message="..." --step=1` appelable par l'IA. Laravel pousse en Server-Sent Events vers le front Next.js.
_Novelty :_ Zéro infra WebSocket, SSE est natif HTTP. Fonctionne avec Claude Code ET Gemini CLI.

**[Architecture #6] Local Web Stack**
_Concept :_ Next.js frontend + Laravel backend, tous deux en localhost. Laravel spawne les processus CLI en sous-processus locaux, capture leur stdout.
_Novelty :_ Souveraineté totale — données, code et agents restent sur la machine. Abonnements CLI gratuits car exécutés localement.

**[Architecture #7] Unidirectional Acyclic Workflow**
_Concept :_ Le workflow est un DAG — un agent ne peut appeler que les nœuds en aval. Seule boucle autorisée : retry intra-agent sur étape `mandatory`.
_Novelty :_ Simplifie radicalement le moteur d'exécution et évite les boucles infinies.

**[Architecture #8] Non-Interactive Full-Prompt Mode**
_Concept :_ Chaque agent reçoit un prompt complet et autonome — contexte `.md` + tâches + instructions JSON. Le CLI tourne en batch sans attente d'input.
_Novelty :_ Exige une bonne ingénierie de prompt dans le YAML, mais garantit des runs non-bloquants et automatisables.

**[Architecture #9] Dated Run Folder Structure**
_Concept :_ Chaque run crée `runs/YYYY-MM-DD-HHMM/` contenant `session.md`, `memory-updates.md`, `README-changes.md`, et les `.md` de chaque agent.
_Novelty :_ Le projet git devient une timeline lisible de l'évolution du code et de sa genèse IA.

**[Architecture #10] Zero Hardcoded Logic**
_Concept :_ Le moteur d'exécution est agnostique au domaine — lit un YAML, spawne des CLIs, gère les états. Aucune logique "dev", "QA" ou "Laravel" dans le code de l'app.
_Novelty :_ L'app est un OS pour agents CLI, pas un outil de dev spécialisé. Applicable à tout workflow textuel.

---

### Thème 3 — Interface Visuelle

**[UX #1] Speech Bubble Step Indicator**
_Concept :_ Bulles BD au-dessus du personnage indiquant l'étape en cours. Transparence en temps réel sans personnalité artificielle.
_Novelty :_ Transforme un processus CLI opaque en narration visuelle lisible par quelqu'un de non-technique.

**[UX #2] Visual Document Handoff**
_Concept :_ Quand un agent termine, son personnage marche vers le bureau du prochain agent et lui tend un document. Le passage de contexte devient une métaphore visuelle.
_Novelty :_ Rend tangible quelque chose d'invisible — le passage de contexte entre prompts CLI.

**[UX #3] Idle vs Working Animation**
_Concept :_ Deux états — idle (immobile à son bureau) et working (animation : tape sur clavier, réfléchit). L'état change dès que l'agent reçoit une tâche.
_Novelty :_ Un coup d'œil suffit pour savoir qui travaille et qui attend.

**[UX #4] Document Walk Handoff**
_Concept :_ Le personnage se lève, traverse l'open space jusqu'au bureau du prochain agent, dépose les documents, revient s'asseoir en idle.
_Novelty :_ Le passage de contexte CLI devient une action narrative mémorable et satisfaisante.

**[UX #5] Parallel Desks, Sequential Walks**
_Concept :_ Dans le cas parallèle, deux bureaux actifs simultanément. Quand les deux terminent, les deux personnages marchent vers le bureau QA.
_Novelty :_ La convergence de branches parallèles devient visuellement intuitive sans graphe à lire.

**[UX #6] Red Alert Error State**
_Concept :_ En cas d'erreur, animation rouge légère sur le bureau concerné — lumière qui pulse. Style urgence cinématographique contenu sur la zone de l'agent.
_Novelty :_ Attire l'attention immédiatement sans être anxiogène. On sait exactement quel agent a planté.

**[UX #7] Error Bubble with Retry**
_Concept :_ Bulle BD avec détail de l'erreur + bouton "Relancer cette étape". Erreur aussi forwardée en `console.error` Chrome.
_Novelty :_ Double canal — UX accessible + console pour debugger. Le retry ne relance que l'étape fautive depuis le dernier checkpoint.

**[UX #8] Bottom Textarea Launcher**
_Concept :_ Barre de lancement en bas — textarea libre + bouton "Lancer". L'utilisateur écrit son brief directement.
_Novelty :_ UX familière (style chat) mais puissante — on part d'une intention naturelle pour lancer un pipeline complexe.

**[UX #9] Workflow Selector → Dynamic Open Space**
_Concept :_ Select en haut pour choisir le workflow YAML actif. Le changement reconfigure l'open space — bureaux selon les agents définis.
_Novelty :_ L'open space est la visualisation du YAML — pas besoin de lire le fichier pour comprendre l'équipe.

**[UX #10] Run History as .md File List**
_Concept :_ Liste des fichiers `.md` générés par run, triés par date. Clic = ouvre le fichier. Le filesystem est la source de vérité.
_Novelty :_ Zéro overhead technique. Les `.md` sont lisibles dans VS Code, Obsidian ou n'importe quel éditeur.

**[UX #11] Pixel Art Visual Style**
_Concept :_ Open space en pixel art — personnages 16x32 avec spritesheet d'animations. Bureaux pixelisés, couleurs pastels douces. Canvas HTML ou sprites CSS / PixiJS.
_Novelty :_ Léger, facile à animer, identité visuelle forte et mémorable.

**[UX #12] Avatar Customization System**
_Concept :_ Avatar personnalisable par agent — couleur, vêtements, accessoires — en calques composables. Stocké dans le YAML ou config UI.
_Novelty :_ L'utilisateur s'approprie son équipe d'agents. Un dev Laravel reconnaissable visuellement.

**[UX #13] Run Completion Modal**
_Concept :_ Modale centrale à la fin du workflow — résumé, statut de chaque agent, lien vers le dossier run.
_Novelty :_ Interruption douce dans l'app — tu sais que c'est fini sans surveiller l'open space.

**[UX #14] Review Dialogue Visualization**
_Concept :_ Lors d'un `review`, deux personnages à un bureau partagé central avec bulles de dialogue alternées.
_Novelty :_ Le processus de validation inter-agents devient un moment narratif visible.

**[UX #15] Specialized Agent Desks**
_Concept :_ Chaque `type` d'agent a un bureau thématique — Testing avec mini-navigateur, DevOps avec serveurs rack, PM avec tableau blanc, Archiviste avec étagères.
_Novelty :_ L'open space est reconnaissable au premier coup d'œil sans lire les noms.

---

### Concept Breakthrough

**Pipeline autonome complet :**
> Brief → PM (routing) → Dev(s) en parallèle → Review (optionnel) → QA → DevOps → Testing (MCP Chromium via YAML) → Bug Loop (`max_retries`) → Archiviste → Documentaliste

Chaque étape est un agent déclaré dans le YAML. Le moteur n'a aucune connaissance du domaine. Les agents Testing, DevOps, Archiviste et Documentaliste sont des YAML configs, pas des features à développer.

---

## Exemple de structure YAML complète

```yaml
name: "Projet E-commerce"
project_path: ~/Projects/ecommerce

agents:
  pm:
    engine: claude-code
    system_prompt_file: prompts/pm.md
    tasks:
      - name: "Analyse et découpage des tâches"
        mandatory: true
        timeout: 180

  laravel-dev:
    engine: claude-code
    system_prompt_file: prompts/laravel-dev.md
    reads_memory: [qa]
    tasks:
      - name: "Analyse de la demande"
        mandatory: false
        timeout: 120
      - name: "Création du plan d'attaque"
        mandatory: false
        timeout: 120
      - name: "Exécution"
        mandatory: false
        timeout: 600
      - name: "Écriture des tests"
        mandatory: true
        timeout: 300
      - name: "Fix PHPStan"
        mandatory: true
        timeout: 180
        max_retries: 3

  nextjs-dev:
    engine: claude-code
    system_prompt_file: prompts/nextjs-dev.md
    tasks:
      - name: "Implémentation composants"
        mandatory: false
        timeout: 600
      - name: "Tests Jest"
        mandatory: true
        timeout: 300

  parallel:
    agents: [laravel-dev, nextjs-dev]

  review:
    type: review
    engine: gemini-cli
    reviews: laravel-dev
    system_prompt_file: prompts/review.md
    criteria: "Vérifie la cohérence des contrats d'API"
    human_review: false

  qa:
    engine: gemini-cli
    system_prompt_file: prompts/qa.md
    reads_memory: [laravel-dev, nextjs-dev]
    tasks:
      - name: "Review code"
        mandatory: true
        timeout: 300
      - name: "Tests d'intégration"
        mandatory: true
        timeout: 300

  archiviste:
    engine: gemini-cli
    system_prompt_file: prompts/archiviste.md
    tasks:
      - name: "Extraction des décisions clés"
        mandatory: true
        timeout: 120

  documentaliste:
    engine: gemini-cli
    system_prompt_file: prompts/documentaliste.md
    tasks:
      - name: "Génération README changes"
        mandatory: true
        timeout: 180
      - name: "Génération changelog"
        mandatory: true
        timeout: 120
```

---

## Prioritisation

### V1 — Core (ce qui fait exister le produit)

| # | Feature | Rationale |
|---|---|---|
| 1 | Moteur YAML → spawn CLI → JSON output | Le cœur, sans ça rien ne fonctionne |
| 2 | Open space pixel art + états idle/working/error | La différenciation visuelle unique |
| 3 | Dual channel : Artisan SSE (bulles) + sidebar `.md` | L'expérience temps réel |
| 4 | Dossier run daté avec tous les `.md` | Traçabilité indispensable dès v1 |
| 5 | Branches parallèles + join | Cas d'usage fullstack Laravel + Next.js |
| 6 | Step-level checkpoint + retry | Robustesse des runs |
| 7 | Workflow selector → open space dynamique | Navigation multi-workflows |

### V2 — Puissance

| # | Feature |
|---|---|
| 8 | Mémoire par agent (`memory/agent.md`) + Archiviste |
| 9 | Review node (agent-sur-agent) |
| 10 | Human review gate optionnel |
| 11 | Documentaliste (README + changelog auto) |
| 12 | Bug Loop auto-correctif (`max_retries` inter-agents) |
| 13 | Cross-agent memory sharing |

### V3 — Vision complète

| # | Feature |
|---|---|
| 14 | Génération YAML via pre-prompt caché |
| 15 | Avatar customisation (calques composables) |
| 16 | `system_prompt_file` externe (`.md` référencé) |
| 17 | Bureaux thématiques par type d'agent |

---

## Session Summary

**55 idées générées** · **3 techniques** · **4 thèmes**

**Vision produit cristallisée :**
> Un orchestrateur CLI universel et agnostique, piloté par des fichiers YAML déclaratifs, avec une interface pixel art temps réel qui rend visible l'invisible — le travail des agents IA. Gratuit par design grâce aux abonnements Claude Code et Gemini CLI. Aucune API payante. Tout en local.

**Différenciation clé vs ChatDev et équivalents :**
- Zéro API key — abonnements CLI existants
- YAML déclaratif = équipe configurable par n'importe quel développeur
- Interface pixel art gamifiée — pas un dashboard de logs
- Dossier run daté = artefact complet et versionnable
- Agnostique au domaine — pas limité au dev logiciel

**Prochaines étapes recommandées :**
1. Définir le schema YAML final (types, champs obligatoires, validation)
2. Prototyper le moteur d'exécution Laravel (spawn CLI, capture stdout, SSE)
3. Créer les premiers sprites pixel art (idle + working pour 1 personnage)
4. Construire le canal SSE end-to-end (Artisan command → Laravel → Next.js)
5. Tester un run complet avec 2 agents séquentiels

---

_Session facilitée avec BMad Brainstorming — 2026-04-02_
