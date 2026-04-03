---
stepsCompleted: [1, 2, 3]
inputDocuments: []
workflowType: 'research'
lastStep: 1
research_type: 'technical'
research_topic: 'Intégration CLI Claude Code / Gemini CLI — mécanismes et contraintes'
research_goals: 'Valider la faisabilité architecturale — vue d ensemble'
user_name: 'Léo'
date: '2026-04-02'
web_research_enabled: true
source_verification: true
---

# Research Report: Technical

**Date:** 2026-04-02
**Author:** Léo
**Research Type:** Technical

---

## Research Overview

Recherche technique sur l'intégration des CLI Claude Code et Gemini CLI dans une architecture d'orchestration d'agents IA multi-rôles. Objectif : valider la faisabilité architecturale et obtenir une vue d'ensemble des mécanismes et contraintes.

---

## Technical Research Scope Confirmation

**Research Topic:** Intégration CLI Claude Code / Gemini CLI — mécanismes et contraintes
**Research Goals:** Valider la faisabilité architecturale — vue d'ensemble

**Technical Research Scope:**

- Architecture Analysis - design patterns, frameworks, system architecture
- Implementation Approaches - development methodologies, coding patterns
- Technology Stack - languages, frameworks, tools, platforms
- Integration Patterns - APIs, protocols, interoperability
- Performance Considerations - scalability, optimization, patterns

**Research Methodology:**

- Current web data with rigorous source verification
- Multi-source validation for critical technical claims
- Confidence level framework for uncertain information
- Comprehensive technical coverage with architecture-specific insights

**Scope Confirmed:** 2026-04-02

---

## Technology Stack Analysis

### Langages et environnements d'exécution

Les CLI Claude Code et Gemini CLI s'utilisent directement depuis le shell (bash/zsh). Les orchestrateurs qui les encapsulent sont le plus souvent écrits en :
- **Node.js / TypeScript** — dominant pour les wrappers CLI (claude-code-workflow, n8n)
- **Python** — dominant pour les frameworks d'orchestration d'agents (LangGraph, CrewAI)
- **Elixir** — cas notable avec pipeline_ex pour la résilience et les pipelines YAML
- **Bash pur** — viable pour des pipelines simples, en combinant `--print` / `--json` et `jq`

_Source: [catlog22/Claude-Code-Workflow](https://github.com/catlog22/Claude-Code-Workflow), [nshkrdotcom/pipeline_ex](https://github.com/nshkrdotcom/pipeline_ex), [DEV.to - Orchestrate Claude Code, Codex, Gemini CLI](https://dev.to/elophanto/how-i-orchestrate-claude-code-codex-and-gemini-cli-as-a-swarm-4p3c)_

---

### Mécanismes d'invocation non-interactive (headless)

#### Claude Code CLI

Flag principal : `-p` / `--print`

```bash
claude -p "Que fait ce module ?"
cat logs.txt | claude -p "Résume ces erreurs"
claude -p "Analyse ce PR" --output-format json | jq -r '.result'
```

Flags clés pour l'automatisation :

| Flag | Usage |
|------|-------|
| `--bare` | Skip CLAUDE.md, hooks, MCP — mode CI reproductible |
| `--output-format text\|json\|stream-json` | Contrôle du format de sortie |
| `--json-schema '<schema>'` | JSON structuré avec schema imposé |
| `--allowedTools "Bash,Read,Edit"` | Pré-approuver les outils (zéro prompts) |
| `--max-turns N` | Limiter les tours de l'agent |
| `--resume <id>` / `--continue` | Reprendre une session existante |
| `--session-id <uuid>` | Fournir un UUID de session précis |
| `--dangerously-skip-permissions` | Bypass de toutes les confirmations |
| `--append-system-prompt` | Injecter un system prompt custom |

Auth en mode headless : via `ANTHROPIC_API_KEY` ou `apiKeyHelper` dans `--settings`.

_Source: [Claude Code - Run programmatically](https://code.claude.com/docs/en/headless), [CLI reference](https://code.claude.com/docs/en/cli-reference)_

#### Gemini CLI

Headless activé automatiquement via `-p` ou détection TTY manquante.

```bash
gemini -p "Résume ce projet"
cat error.log | gemini -p "Quelle est la cause ?"
gemini -p "Explique Docker" --output-format json | jq '.response'
```

Flags clés :

| Flag | Usage |
|------|-------|
| `--prompt` / `-p` | Prompt inline — bypass UI interactif |
| `--output-format text\|json\|stream-json` | Format de sortie |
| `--yolo` / `-y` | Auto-approve tous les tool calls |
| `--model` / `-m` | Sélectionner une version de modèle |

Codes de sortie : `0` = succès, `1` = erreur API, `42` = erreur d'input, `53` = turn limit.

_Source: [Gemini CLI - Headless Mode (Official Docs)](https://google-gemini.github.io/gemini-cli/docs/cli/headless.html)_

---

### Frameworks d'orchestration existants

| Outil | Langage | Approche |
|-------|---------|----------|
| [claude-code-workflow](https://github.com/catlog22/Claude-Code-Workflow) | Node.js | JSON-driven, 22 rôles agents, multi-CLI (Claude+Gemini+Codex), dashboard terminal |
| [pipeline_ex](https://github.com/nshkrdotcom/pipeline_ex) | Elixir | YAML-pipelines, Claude+Gemini, session state, fault-tolerance |
| [LangGraph](https://www.langchain.com/langgraph) | Python | Graph-based, stateful, 24k+ stars |
| [CrewAI](https://crewai.com/open-source) | Python | Role-playing multi-agent, 44k+ stars |
| [n8n](https://github.com/n8n-io/n8n) | Node.js | Visual workflow, 80k+ stars, nœuds AI natifs |
| Microsoft Agent Framework | Multi | AutoGen + Semantic Kernel fusionnés, GA Q1 2026 |

_Source: [Best Open Source Agent Frameworks 2026 - Firecrawl](https://www.firecrawl.dev/blog/best-open-source-agent-frameworks)_

---

### Rate limits et contraintes opérationnelles

#### Claude Code (abonnement Max / API Key)

| Mode | Limite |
|------|--------|
| Claude Max (abonnement) | Fenêtre glissante 5h + plafond 7 jours — **non exposée programmatiquement** |
| API Key Tier 1 | ~20K input / 4K output TPM (Sonnet) |
| API Key Tier 2 | ~40K / 8K TPM |
| API Key Tier 3 | ~80K / 16K TPM |

Limitation connue : aucun endpoint pour requêter le % de quota utilisé sur un plan Max — [issue GitHub #32796](https://github.com/anthropics/claude-code/issues/32796).
Flag `--max-budget-usd` = seul plafond dur par run disponible en scripting.

_Source: [Claude Code Rate Limits - Anthropic](https://platform.claude.com/docs/en/api/rate-limits), [TrueFoundry](https://www.truefoundry.com/blog/claude-code-limits-explained)_

#### Gemini CLI (authentification Google)

| Auth | Limite journalière | Modèles |
|------|-------------------|---------|
| Google Account (Code Assist) | **1 000 req/jour** | Famille complète |
| API Key gratuite | **250 req/jour** | Flash uniquement |
| Google AI Pro | 1 500 req/jour | Famille complète |
| Google AI Ultra | 2 000 req/jour | Famille complète |

Note : quota **par projet**, pas par clé — plusieurs clés d'un même projet partagent le même pool. RPM free tier : 5-15 selon modèle. Gemini 2.5 Pro atteint les limites après ~10-15 prompts sur le free tier.

_Source: [Gemini CLI - Quotas and Pricing](https://geminicli.com/docs/resources/quota-and-pricing/), [Gemini API Rate Limits - Google](https://ai.google.dev/gemini-api/docs/rate-limits)_

---

### Gestion du contexte et état inter-invocations

Pratiques établies (source : [Anthropic Engineering - Effective Context Engineering](https://www.anthropic.com/engineering/effective-context-engineering-for-ai-agents)) :

1. **Compaction de contexte** — Résumer l'historique avant chaque nouvelle invocation, conserver décisions archi + blockers, éliminer les sorties d'outils redondantes.
2. **Notes externes structurées** — L'agent écrit dans un fichier partagé (`NOTES.md` / `state.json`) lu en premier à chaque invocation.
3. **Délégation sub-agents** — L'orchestrateur délègue des tâches ciblées avec un contexte propre ; les sub-agents retournent des résumés 1-2K tokens.
4. **Chargement Just-in-Time** — Charger uniquement les fichiers nécessaires à l'étape courante, pas tout en amont.
5. **Isolation par worktree git** — Un worktree par agent évite la contamination d'état entre invocations concurrentes.

Pattern stateless simple :
```
Step N → écrit state.json → Step N+1 lit state.json → ...
```

_Source: [Anthropic Engineering](https://www.anthropic.com/engineering/effective-context-engineering-for-ai-agents), [Google Developers Blog](https://developers.googleblog.com/architecting-efficient-context-aware-multi-agent-framework-for-production/)_

## Integration Patterns Analysis

### MCP (Model Context Protocol) — protocole de référence

**Standard ouvert lancé par Anthropic en novembre 2024**, MCP est devenu le standard de facto de l'industrie mi-2025. Il fonctionne comme un "USB-C pour l'IA" : un connecteur universel entre les clients LLM et les outils externes, bases de données, APIs, ou autres agents.

**Architecture :**
- Un *host* (ex : Claude Code) démarre et se connecte à des *servers* MCP (processus locaux ou services distants)
- À la connexion, le host demande "quelles capacités offres-tu ?" → les servers répondent avec leurs Tools, Resources, Prompts, Sampling
- Claude Code joue un **double rôle** : client MCP (consomme des servers) et server MCP (exposable à d'autres systèmes)
- Depuis 2026 : jusqu'à **10 sous-agents simultanés** dans Claude Code

**Agent Teams (natif Claude Code 2026) :** Un "team lead" décompose les tâches en liste partagée ; les "teammates" tournent dans des context windows isolées et peuvent se messagerie directement (peer-to-peer, pas seulement hub-and-spoke).

_Source: [Anthropic - Introducing MCP](https://www.anthropic.com/news/model-context-protocol), [Claude Code Agent Teams docs](https://code.claude.com/docs/en/agent-teams), [Shipyard - Claude Code multi-agent 2026](https://shipyard.build/blog/claude-code-multi-agent/)_

---

### Transports IPC entre agents CLI

| Mécanisme | Usage | Notes |
|-----------|-------|-------|
| **stdio (stdin/stdout)** | Transport MCP local par défaut | JSON-RPC 2.0 délimité par newlines ; universel, process-isolé, debuggable trivially |
| **Unix sockets / Named pipes** | Haute perf même machine | Passe par le kernel (pas de TCP stack) ; débit supérieur à stdio pour gros volumes |
| **HTTP + SSE** | Servers MCP distants | POST JSON-RPC, réponses en SSE — **SSE déprécié** dans les nouvelles specs MCP |
| **HTTP + JSON-LD (ANP)** | Réseaux d'agents décentralisés | Identité cryptographique (DIDs), interopérabilité sémantique Schema.org |

**Autres protocoles émergents (2025-2026) :**
- **A2A** (Agent-to-Agent, Google) : HTTP + SSE optionnel, Agent Cards pour la découverte de capacités
- **ACP** (Agent Communication Protocol) : REST-native, async-first, messages MIME multimodaux
- **ANP** (Agent Network Protocol) : décentralisé, trustless, HTTP + JSON-LD + TLS + DIDs

_Source: [arxiv - Survey MCP/A2A/ACP/ANP](https://arxiv.org/html/2505.02279v1), [foojay.io - MCP via raw stdio](https://foojay.io/today/understanding-mcp-through-raw-stdio-communication/)_

---

### Formats d'échange de données

| Format | Rôle dans le pipeline |
|--------|----------------------|
| **JSON** | Échange runtime universel — arguments/résultats des tool calls MCP, payloads agent-to-agent |
| **YAML** | Configuration et contexte — définitions d'agents, pipelines déclaratifs, topologies de workflow |
| **Markdown / CLAUDE.md** | Mémoire persistante cross-session — contexte projet, conventions, patterns interdits |
| **MIME multipart (ACP)** | Messages multimodaux : code + screenshots + logs dans une même enveloppe |

Pattern de chaînage pratique :
```
Agent Plan (YAML config) → produit JSON artifact
  → Agent Architecture valide JSON
    → Agent Dev (reçoit JSON spec, produit code)
      → Agent QA (lit code, retourne JSON test report)
```

_Source: [DEV.to - Structure Claude Code for production (2026)](https://dev.to/lizechengnet/how-to-structure-claude-code-for-production-mcp-servers-subagents-and-claudemd-2026-guide-4gjn)_

---

### Patterns de routage inter-agents

**LLM-based routing** — état de l'art actuel : un LLM gating (petit modèle, faible coût) classifie l'intent et sélectionne l'agent cible. Gère les inputs ambigus et ouverts que les règles ne capturent pas.

**Semantic/Embedding routing** — intent encodé comparé à des vecteurs de capacités d'agents via similarité cosinus. Souvent combiné avec LLM en fallback.

**Config-driven (YAML/JSON)** — couche déclarative par-dessus le routage : capabilities, règles, topologie définis en config versionnée. Modifiable sans redéploiement de code.

**Topologies architecturales :**
- **Orchestrateur-workers** (~70% des déploiements prod) : central orchestrator → specialists
- **Hiérarchique** : arbres de routage multi-niveaux
- **Pair-à-pair / swarm** : les agents négocient les handoffs directement

_Source: [Patronus AI - AI Agent Routing](https://www.patronus.ai/ai-agent-development/ai-agent-routing), [Google ADK - Multi-Agent Patterns](https://developers.googleblog.com/developers-guide-to-multi-agent-patterns-in-adk/)_

---

### Orchestration event-driven

**Redis Streams** — référence pour les pipelines IA : 650k msgs/sec, p95 ~8ms, consumer groups, replay d'événements. Redis unifie : Streams (event sourcing) + Pub/Sub (broadcast temps réel) + vector search (mémoire sémantique).

**BullMQ (Node.js, Redis-backed)** — chaque invocation d'agent = un job avec priorité, retries, delays, rate limiting, concurrence workers. Event emitters natifs pour réagir aux changements d'état (`completed`, `failed`, `stalled`).

| Cas d'usage | Tooling recommandé |
|-------------|-------------------|
| Haut débit distribué | Apache Kafka |
| Faible latence | Redis Streams, NATS JetStream |
| Serverless / cloud | AWS EventBridge, Google Pub/Sub |
| Job queues background | BullMQ, Temporal |

_Source: [Redis - AI Agent Orchestration](https://redis.io/blog/ai-agent-orchestration/), [BullMQ docs](https://docs.bullmq.io/)_

---

### Saga Pattern pour workflows multi-étapes

Le **Saga pattern** est le modèle recommandé par AWS Prescriptive Guidance pour les pipelines IA agentiques qui touchent des états externes (fichiers, APIs, git). Chaque étape a une **transaction compensatoire** en cas d'échec.

**SagaLLM (arXiv:2503.11951, mars 2025)** — framework formel qui intègre Saga dans le multi-agent LLM :
- 3 dimensions d'état : Application State, Operation State, Dependency State
- Agents validateurs indépendants (évite l'auto-validation par le LLM)
- Compensation automatique + replanification avec contexte préservé
- Exécution en deux phases : plan humain → génération auto de l'architecture d'agents, règles de validation, graphes de dépendances

**Tooling production :** Temporal.io (compensation native), AWS Step Functions, Conductor/Orkes.

_Source: [SagaLLM - arXiv:2503.11951](https://arxiv.org/html/2503.11951), [AWS - Saga Orchestration Patterns](https://docs.aws.amazon.com/prescriptive-guidance/latest/agentic-ai-patterns/saga-orchestration-patterns.html)_

---

### Comparatif frameworks — gestion des handoffs

| Framework | Routage | Modèle d'état | Handoff | Meilleur pour |
|-----------|---------|---------------|---------|---------------|
| **LangGraph** | Conditional graph edges | Typed shared object + checkpoints | Traversée d'arête | Workflows stateful complexes |
| **CrewAI** | Prompt-encoded delegation | Sequential task outputs | Role-to-role | Prototypage rapide |
| **OpenAI Agents SDK** | Tool invocation | Conversation context | Tool call | Chaînes linéaires simples |
| **Google ADK** | AutoFlow + output_key | Session state dict | Sub-agent delegation | Intégration Google Cloud |

_Source: [Particula - LangGraph vs CrewAI vs OpenAI Agents SDK 2026](https://particula.tech/blog/langgraph-vs-crewai-vs-openai-agents-sdk-2026), [IBM Developer - Comparing AI Agent Frameworks](https://developer.ibm.com/articles/awb-comparing-ai-agent-frameworks-crewai-langgraph-and-beeai/)_

<!-- Content will be appended sequentially through research workflow steps -->
