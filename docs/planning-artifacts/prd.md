---
stepsCompleted: [step-01-init, step-02-discovery, step-02b-vision, step-02c-executive-summary, step-03-success, step-04-journeys, step-05-domain, step-06-innovation, step-07-project-type, step-08-scoping, step-09-functional, step-10-nonfunctional, step-11-polish, step-12-complete]
inputDocuments:
  - docs/planning-artifacts/research/technical-claude-gemini-cli-integration-research-2026-04-02.md
  - docs/brainstorming/brainstorming-session-2026-04-02-1000.md
workflowType: 'prd'
classification:
  projectType: web_app
  domain: general
  complexity: medium
  projectContext: greenfield
briefCount: 0
researchCount: 1
brainstormingCount: 1
projectDocsCount: 0
---

# Product Requirements Document - XuMaestro

**Author:** Léo
**Date:** 2026-04-02

---

## Executive Summary

XuMaestro est un orchestrateur d'agents IA CLI local, piloté par des fichiers YAML déclaratifs. Il permet à un développeur de définir une équipe d'agents spécialisés (Claude Code, Gemini CLI) exécutés en pipeline séquentiel, avec un contexte ciblé par agent. L'application est destinée à un usage personnel — remplacer Claude Code en usage solo quotidien, au travail comme en perso — en résolvant la dégradation structurelle d'un agent unique gérant trop de responsabilités sur une fenêtre de contexte partagée.

**Problème central :** Un agent qui fait tout oublie des étapes, dérive et produit des sorties de qualité dégradée dès que la tâche est complexe — même avec un CLAUDE.md exhaustif. La cause n'est pas le modèle mais l'architecture : un seul contexte partagé pour tout.

**Solution :** Diviser le travail en agents spécialisés à contexte ciblé, enchaînés dans un ordre logique imposé par le développeur via YAML. Chaque agent reçoit exactement ce dont il a besoin — ni plus, ni moins.

### What Makes This Special

- **Zéro API payante :** exploite les abonnements CLI existants (Claude Code + Gemini CLI) — aucun coût variable à l'usage.
- **YAML déclaratif versionnable :** l'équipe d'agents, leur ordre, leurs tâches et leurs system prompts sont dans des fichiers texte versionnables avec git — aucune config enfouie dans une UI.
- **Moteur agnostique au domaine :** le moteur orchestre des états (idle, working, error, done) et passe des contextes — sans aucune connaissance du domaine métier. N'importe quel workflow textuel peut être modélisé.
- **Diagramme d'agents temps réel :** l'état de chaque agent, les transitions et les erreurs sont visibles en temps réel dans un diagramme expressif — sans avoir à lire des logs. Rend visible l'invisible.
- **Artefacts de run complets :** chaque exécution produit un dossier daté (`runs/YYYY-MM-DD-HHMM/`) avec session log et outputs par agent — traçabilité totale, versionnable avec git.

## Project Classification

- **Type de projet :** Web App (SPA locale — Next.js + Laravel, temps réel SSE)
- **Domaine :** Productivité développeur / orchestration IA (général)
- **Complexité :** Moyenne — architecture événementielle, spawn de processus CLI, patterns agent-to-agent ; aucune contrainte réglementaire
- **Contexte :** Greenfield

## Success Criteria

### User Success

- Premier run complet sans intervention humaine sur un workflow à 2+ agents séquentiels = définition du succès
- XuMaestro devient le point d'entrée de tout développement — Claude Code n'est plus ouvert directement, il est invoqué en subprocess par le moteur
- Un run qui reprend depuis un checkpoint après échec partiel est considéré un succès (pas un échec)

### Business Success

- Adoption complète dans le workflow de développement quotidien (travail + projets perso)
- Aucune régression sur la qualité produite vs Claude Code solo — les agents spécialisés produisent au moins aussi bien, étape par étape

### Technical Success

- Aucun run ne bloque indéfiniment : timeout automatique configurable par tâche (`timeout: N` en YAML)
- Erreur surfacée immédiatement via bulle d'alerte dans l'UI + `console.error`
- Checkpoint step-level opérationnel : un retry repart depuis la dernière étape complétée, pas depuis le début
- Contrat JSON de sortie (`{ step, status, output, next_action, errors }`) parsé de façon fiable par le moteur

### Measurable Outcomes

- ✅ Run complet sans intervention sur workflow 2+ agents séquentiels
- ✅ Timeout auto déclenché sur tout CLI bloqué
- ✅ Reprise depuis checkpoint après échec partiel fonctionnelle
- ✅ Zéro ouverture directe de Claude Code pour des tâches de développement

## Product Scope & Roadmap

### MVP Strategy & Philosophy

**Approche MVP :** Problem-solving MVP — le minimum qui exécute un run complet séquentiel sans intervention humaine et prouve l'architecture core.

**Hypothèse critique à valider en premier :** La fiabilité du mode headless CLI (`claude -p`, `gemini -p`) sur des tâches réelles — sortie JSON structurée, absence de prompts interactifs bloquants, comportement sous timeout. Cette validation précède tout développement UI.

**Ressources :** Solo développeur — scope MVP intentionnellement conservateur.

### MVP Feature Set (Phase 1)

**Capacités obligatoires :**

| # | Capacité | Justification |
|---|---|---|
| 1 | Moteur YAML → spawn CLI → capture stdout → JSON output | Cœur sans lequel rien ne fonctionne |
| 2 | Pipeline séquentiel uniquement | Complexité maîtrisée pour le V1 |
| 3 | Diagramme classique (nœuds/arêtes) avec états idle/working/error/done | Visibilité minimale indispensable |
| 4 | Dual channel : SSE bulles + sidebar `.md` append-only | Expérience temps réel core |
| 5 | Dossier run daté `runs/YYYY-MM-DD-HHMM/` avec artefacts | Traçabilité dès V1 |
| 6 | Step-level checkpoint + retry automatique (`mandatory`, `max_retries`) | Robustesse sans laquelle le MVP n'est pas utilisable |
| 7 | Timeout automatique par tâche + alerte bulle | Aucun run ne bloque indéfiniment |
| 8 | Workflow selector → diagramme dynamique selon YAML actif | Navigation multi-workflows |

**Fallback si scope trop large :** couper le workflow selector (YAML hardcodé) et la sidebar `.md` (log console) en dernier recours.

### Growth Features (Phase 2)

- Branches parallèles + join (agents simultanés, max 5)
- Mémoire par agent (`memory/agent.md`) + agent Archiviste
- Review node : agent-sur-agent avec possibilité de forcer un retry en amont
- Human review gate optionnel (`human_review: true` par étape)
- Agent Documentaliste (génération README + changelog à chaque run)
- Bug Loop auto-correctif inter-agents
- Cross-agent memory sharing (`reads_memory: [qa, pm]`)

### Vision (Phase 3)

- Interface pixel art open space (personnages, spritesheet, animations handoff)
- Génération YAML via pre-prompt caché (onboarding conversationnel)
- Avatar customisation par agent (calques composables)
- Bureaux thématiques par type d'agent

### Risk Mitigation

**Fiabilité headless CLI :** Valider en premier sprint avec un prototype minimal (spawn CLI, tâche simple, parse JSON). Si le JSON est instable → prompt engineering ajusté ou couche de parsing défensive.

**SSE stability sur run long :** Les runs peuvent durer 10-20 min. Le client SSE implémente une reconnexion automatique.

**Scope creep :** Le parallèle, la mémoire, et le review node sont explicitement hors MVP. À résister jusqu'à ce que le séquentiel soit validé en production réelle.

## User Journeys

### Journey 1 — Run complet séquentiel (MVP — Happy Path)

**Contexte :** Léo a une nouvelle feature à développer sur son projet Laravel au travail. Avant XuMaestro, il ouvrait Claude Code, écrivait un prompt long, et espérait que l'agent ne déraille pas à l'étape 6 sur 8.

**Scène d'ouverture :** Il ouvre XuMaestro dans le navigateur. Le sélecteur affiche `feature-dev.yaml` — son équipe séquentielle : PM → Laravel Dev → QA → DevOps. Il écrit son brief dans le textarea en bas : *"Ajouter un système de notifications in-app avec badge counter et dropdown"*. Il clique Lancer.

**Action :** Le diagramme s'anime. PM est `working`. Une bulle SSE apparaît : *"Brief analysé — plan de développement établi. Passage à Laravel Dev."* PM passe en `done`, la flèche s'active vers le nœud suivant. La sidebar `.md` s'enrichit en temps réel — Léo peut voir chaque décision, chaque fichier modifié, sans rien faire.

**Climax :** Laravel Dev travaille, QA reçoit le contexte complet et valide, DevOps déploie en local. Chaque transition entre agents est visible dans le diagramme — une flèche s'active, le nœud précédent marque `done`, le suivant passe en `working`.

**Résolution :** Modal de fin : *"4 agents · 11 min · 0 erreur. Dossier : `runs/2026-04-02-1430/`"*. Le code est là, les tests passent. Léo n'a pas touché Claude Code une seule fois.

---

### Journey 2 — Run qui déraille à mi-chemin (Edge Case)

**Contexte :** Laravel Dev se fige après 120 secondes sans output — une tâche de migration complexe dépasse le timeout.

**Scène d'ouverture :** Le nœud Laravel Dev passe en `error` dans le diagramme (signal visuel expressif localisé sur le nœud). Une bulle d'alerte apparaît : *"Laravel Dev — Étape 3/5 : timeout après 120s. Checkpoint sauvegardé."*

**Action :** Léo lit l'erreur dans la bulle. Il clique "Relancer cette étape". Le moteur recharge le contexte jusqu'au checkpoint 2, relance uniquement l'étape 3.

**Résolution :** L'étape 3 passe au second essai. Le workflow reprend depuis là où il s'était arrêté — aucune étape déjà complétée n'est rejouée. Léo note que le timeout migrations devrait être 180s — il met à jour son YAML.

---

### Journey 3 — Construire une nouvelle équipe (Configuration)

**Contexte :** Léo veut créer un workflow `content-writing.yaml` — PM qui structure, Writer qui rédige, Reviewer qui critique.

**Scène d'ouverture :** Il crée `workflows/content-writing.yaml` dans son éditeur, définit 3 agents avec leurs `system_prompt_file`, timeouts adaptés (60-120s), et le champ `name: "Rédaction de specs"`.

**Action :** Il revient dans XuMaestro, sélectionne "Rédaction de specs" dans le dropdown. Le diagramme se reconfigure : 3 nœuds en séquence. Il lance un premier test.

**Résolution :** Après 2 itérations d'ajustement des system prompts, le workflow produit des specs de qualité autonome. Réutilisable sur tous ses projets.

---

### Journey Requirements Summary

| Journey | Capacités révélées |
|---|---|
| Happy Path séquentiel | Moteur YAML, spawn CLI séquentiel, SSE temps réel, transitions visuelles, modal de fin, dossier run |
| Edge Case | Timeout configurable, état d'erreur expressif sur nœud, checkpoint step-level, retry partiel |
| Configuration | Workflow selector avec `name`, rechargement dynamique du diagramme, hot-reload YAML |

## Innovation & Novel Patterns

### Detected Innovation Areas

**1. Modèle économique "gratuit par construction"**
Les orchestrateurs existants (LangGraph, CrewAI, n8n, claude-code-workflow) requièrent tous des API keys avec coût variable à l'usage. XuMaestro exploite les abonnements CLI (Claude Code Max, Gemini Code Assist) — coût fixe, quotas généreux. Le coût marginal par run est zéro.

**2. YAML déclaratif comme interface principale**
La plupart des orchestrateurs exposent une API code (Python/Node) ou une UI drag-and-drop. XuMaestro utilise le fichier texte versionnable comme interface primaire — config workflow dans git, diffable, reviewable, partageable. Pas de base de données, pas d'UI de configuration.

**3. Moteur agnostique au domaine**
Le moteur n'a aucune connaissance de ce que font les agents — il orchestre des états et passe des contextes. Un workflow de dev Laravel, de rédaction de specs, ou d'analyse de données utilise la même infrastructure. C'est un OS pour agents CLI.

**4. Contexte ciblé comme solution à la dégradation de qualité**
La dégradation de qualité d'un LLM seul vient du contexte partagé grandissant, pas du modèle. Isoler chaque agent avec un contexte ciblé (cycle `.md` + tâches spécifiques) restaure la qualité sans changer de modèle. Approche architecturale, pas une amélioration de prompt.

### Market Context & Competitive Landscape

- `claude-code-workflow` (Node.js) — 22 rôles, multi-CLI, mais orienté dev logiciel uniquement
- `pipeline_ex` (Elixir) — YAML-pipelines, fault-tolerance, mais complexité d'installation élevée
- LangGraph / CrewAI — puissants mais Python-only, API keys requises, courbe d'apprentissage
- n8n — visual workflow, mais pas optimisé pour CLI headless AI

**Positionnement XuMaestro :** interface web locale simple, YAML lisible par tout développeur, zéro API key, domaine-agnostique, dossiers run git-friendly.

### Validation Approach

- **Architecture :** Un run complet séquentiel sans intervention sur un vrai projet = validation core
- **Qualité :** Comparer output XuMaestro multi-agents vs Claude Code solo sur la même tâche
- **Modèle économique :** Zéro friction — pas de carte de crédit, pas de clé API à générer

### Innovation Risk Mitigation

| Risque | Mitigation |
|---|---|
| Claude Code / Gemini CLI modifient leur interface headless | Couche d'abstraction driver isolée — seul le driver change |
| Rate limits sur les abonnements CLI | `engine` par agent permet de basculer entre Claude Code et Gemini |
| YAML trop complexe à écrire | `system_prompt_file` externe + structure simple V1 — V3 introduit la génération IA |

## Web App — Exigences Spécifiques

### Technical Architecture

**Stack local :**
- **Frontend :** Next.js (React / TypeScript) — SPA, rendu client uniquement, pas de SSR
- **Backend :** Laravel — REST API avec Laravel Resources (JSON), spawn des processus CLI en sous-processus
- **Communication :** REST (POST pour déclencher un run, GET pour les ressources) + SSE unidirectionnel Laravel → Next.js pour les événements temps réel
- **State management :** Zustand — stores dédiés (workflow store, run store, agent status store), mis à jour directement par le listener SSE

**Flux d'un run :**
```
[User] POST /api/runs {workflow, brief}
  → Laravel crée le run, spawn le premier agent CLI
  → SSE stream ouvert : /api/runs/{id}/stream
  → Événements SSE → Zustand store → React re-render ciblé
  → Laravel appende au session.md à chaque étape
  → GET /api/runs/{id}/log pour la sidebar .md
```

### Browser & Deployment

- **Cible :** Chrome / Chromium desktop uniquement — outil local développeur
- **Déploiement :** localhost uniquement — pas de considérations SEO, responsive, ou accessibilité avancée
- **SSE :** support natif, pas de polyfills

### Performance Targets

- Maximum 5 agents en parallèle (Growth) — rendu trivial pour le diagramme
- Latence SSE → UI : < 200ms en conditions localhost
- Données en mémoire Zustand pour la session active — pas de base de données, pas de pagination

### YAML Management

- **Chargement :** au démarrage + bouton "Recharger" manuel
- **Validation :** côté Laravel avant lancement (schema YAML vérifié)
- **Source de vérité :** filesystem (`workflows/*.yaml`) — aucun état YAML persisté en base

### Implementation Constraints

- **SSE lifecycle :** ouvert au lancement du run, fermé à la complétion ou erreur
- **Subprocess management :** Laravel gère lifecycle CLI spawned (timeout, kill, stdout capture) — le frontend ne connaît pas les PIDs
- **Laravel Resources :** une Resource par entité exposée (Run, Agent, Step) — contrat JSON stable entre versions
- **Pas de WebSocket :** SSE suffit pour le flux unidirectionnel ; les commandes utilisateur restent des requêtes REST

## UX Design Principles

Ces principes s'appliquent dès le MVP et guident toutes les décisions de design d'interface, y compris le diagramme classique de la Phase 1.

- **Rendre visible l'invisible :** le diagramme n'est pas un dashboard de logs — c'est une représentation de qui travaille, qui attend, qui a réussi. Un coup d'œil suffit pour comprendre l'état du run sans lire quoi que ce soit.
- **États expressifs :** chaque état d'agent doit être visuellement distinct et sans ambiguïté. L'état `error` est localisé sur le nœud concerné avec un signal d'urgence contenu (ex : indicateur rouge pulsant) — visible immédiatement, sans être anxiogène pour le reste du diagramme.
- **Transitions animées entre agents :** le handoff (passage du contexte d'un agent au suivant) doit être une transition visible dans le diagramme — pas une simple disparition/apparition. L'animation rend tangible le passage de contexte qui se déroule en réalité dans les fichiers `.md`.
- **Satisfaction de progression :** chaque étape complétée doit apporter une micro-récompense visuelle (nœud marqué `done`, flèche activée). Le run doit ressembler à une avancée, pas à une liste de logs qui défile.

## Functional Requirements

### Gestion des Workflows

- **FR1 :** L'utilisateur peut sélectionner un workflow parmi les fichiers YAML disponibles dans le dossier `workflows/`
- **FR2 :** Le système affiche le champ `name` défini dans le YAML comme libellé du workflow dans le sélecteur
- **FR3 :** L'utilisateur peut recharger manuellement la liste des workflows sans redémarrer l'application
- **FR4 :** Le système valide la structure d'un fichier YAML avant d'accepter le lancement d'un run
- **FR5 :** L'utilisateur peut visualiser le diagramme des agents d'un workflow sélectionné avant de lancer un run
- **FR6 :** Le système configure chaque agent (nom, engine, tâches, timeouts, system prompt) depuis les champs du YAML

### Exécution & Orchestration des Agents

- **FR7 :** L'utilisateur peut lancer un run en soumettant un brief textuel libre
- **FR8 :** Le système exécute les agents dans l'ordre séquentiel défini dans le YAML
- **FR9 :** Le système spawn un processus CLI (Claude Code ou Gemini CLI) par agent selon le champ `engine` du YAML, depuis le `project_path` défini
- **FR10 :** Le système injecte le system prompt de l'agent (inline ou via fichier externe référencé par `system_prompt_file`) à chaque invocation CLI
- **FR11 :** Le système applique un timeout configurable par tâche et interrompt le processus CLI en cas de dépassement
- **FR12 :** Le système retente automatiquement une étape marquée `mandatory: true` après échec, dans la limite de `max_retries`
- **FR13 :** L'utilisateur peut annuler un run en cours

### Communication & Contexte Inter-agents

- **FR14 :** Le système maintient un fichier de contexte partagé (cycle `.md`) mis à jour après chaque étape
- **FR15 :** Chaque agent reçoit le fichier de contexte partagé en entrée, en plus de son brief de tâche
- **FR16 :** Le système capture et parse la sortie JSON structurée de chaque agent (`{ step, status, output, next_action, errors }`)
- **FR17 :** Le système transmet l'output JSON d'un agent comme entrée de contexte à l'agent suivant

### Monitoring Temps Réel

- **FR18 :** L'utilisateur peut voir l'état de chaque agent (idle / working / error / done) dans le diagramme, mis à jour en temps réel
- **FR19 :** Le diagramme anime la transition entre agents lors d'un handoff
- **FR20 :** L'utilisateur reçoit des notifications de progression sous forme de bulles associées à l'agent actif
- **FR21 :** L'utilisateur peut consulter le log complet de la session en cours dans une sidebar append-only, mise à jour en temps réel
- **FR22 :** L'utilisateur reçoit une alerte visuelle localisée sur le nœud concerné avec le détail de l'erreur en cas d'échec d'un agent
- **FR23 :** L'utilisateur voit un récapitulatif de fin de run (agents exécutés, durée totale, statut, lien vers le dossier)

### Gestion des Erreurs & Résilience

- **FR24 :** Le système sauvegarde un checkpoint après chaque étape complétée avec succès
- **FR25 :** L'utilisateur peut relancer une étape en erreur depuis le dernier checkpoint sans rejouer les étapes précédentes
- **FR26 :** Le système libère proprement les ressources d'un processus CLI interrompu (timeout ou annulation)
- **FR27 :** Le système expose le message d'erreur et l'étape concernée dans l'alerte visible par l'utilisateur

### Artefacts & Traçabilité

- **FR28 :** Le système crée un dossier de run daté (`runs/YYYY-MM-DD-HHMM/`) pour chaque exécution
- **FR29 :** Le système génère et maintient un fichier `session.md` append-only contenant la trace complète du run
- **FR30 :** Le système sauvegarde les outputs de chaque agent dans le dossier run
- **FR31 :** L'utilisateur peut accéder à la liste des runs passés depuis l'interface

## Non-Functional Requirements

### Performance

- **NFR1 :** La latence entre un événement agent (changement d'état, bulle) et son affichage dans l'UI ne dépasse pas 200ms en conditions localhost
- **NFR2 :** Le spawn d'un processus CLI et sa première sortie capturée interviennent en moins de 5 secondes
- **NFR3 :** Le rendu du diagramme (jusqu'à 5 nœuds actifs) reste fluide sans dégradation visible

### Fiabilité

- **NFR4 :** Aucun processus CLI ne reste en état zombie après un timeout, une annulation ou une erreur — le moteur nettoie les ressources dans tous les cas
- **NFR5 :** Le client SSE tente une reconnexion automatique en cas de déconnexion pendant un run actif
- **NFR6 :** Un checkpoint est écrit sur disque avant qu'une étape soit marquée complétée — aucune perte de progression en cas de crash Laravel
- **NFR7 :** Si la sortie d'un agent n'est pas du JSON valide, l'erreur est capturée et exposée — le moteur ne crashe pas silencieusement

### Intégration

- **NFR8 :** Le moteur supporte `claude-code` (flag `-p`) et `gemini-cli` (flag `-p`) comme engines headless interchangeables
- **NFR9 :** Le contrat de sortie JSON (`{ step, status, output, next_action, errors }`) est validé structurellement à chaque réponse agent avant traitement
- **NFR10 :** Une modification de la syntaxe CLI headless ne nécessite qu'un changement dans la couche driver, sans toucher le moteur core

### Sécurité (périmètre local)

- **NFR11 :** Les processus CLI spawned s'exécutent exclusivement depuis le `project_path` défini dans le YAML
- **NFR12 :** Les credentials, clés API et tokens présents dans l'environnement ne sont jamais logués dans `session.md` ou les artefacts de run
