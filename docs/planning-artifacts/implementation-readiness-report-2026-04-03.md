---
stepsCompleted: [1, 2, 3, 4, 5, 6]
status: complete
completedAt: '2026-04-03'
status: in_progress
documentsUsed:
  prd: docs/planning-artifacts/prd.md
  architecture: docs/planning-artifacts/architecture.md
  uxDesign: docs/planning-artifacts/ux-design-specification.md
  epics: docs/planning-artifacts/epics.md
---

# Implementation Readiness Assessment Report

**Date:** 2026-04-03
**Project:** xu-workflow

---

## Inventaire des Documents

| Type | Fichier | Taille | Modifié | Statut |
|---|---|---|---|---|
| PRD | `docs/planning-artifacts/prd.md` | 22 574 o | 2 avr. 2026 | ✅ Trouvé |
| Architecture | `docs/planning-artifacts/architecture.md` | 32 492 o | 3 avr. 2026 | ✅ Trouvé |
| UX Design | `docs/planning-artifacts/ux-design-specification.md` | 32 909 o | 3 avr. 2026 | ✅ Trouvé |
| Epics & Stories | `docs/planning-artifacts/epics.md` | 32 804 o | 3 avr. 2026 11:33 | ✅ Trouvé |

**Doublons :** Aucun
**Documents manquants :** Aucun
**Couverture :** 4/4 documents requis présents

---

## Analyse PRD

### Exigences Fonctionnelles

**Gestion des Workflows (FR1–FR6)**
- FR1 : L'utilisateur peut sélectionner un workflow parmi les fichiers YAML disponibles dans `workflows/`
- FR2 : Le système affiche le champ `name` défini dans le YAML comme libellé du workflow dans le sélecteur
- FR3 : L'utilisateur peut recharger manuellement la liste des workflows sans redémarrer l'application
- FR4 : Le système valide la structure d'un fichier YAML avant d'accepter le lancement d'un run
- FR5 : L'utilisateur peut visualiser le diagramme des agents d'un workflow sélectionné avant de lancer un run
- FR6 : Le système configure chaque agent (nom, engine, tâches, timeouts, system prompt) depuis les champs du YAML

**Exécution & Orchestration (FR7–FR13)**
- FR7 : L'utilisateur peut lancer un run en soumettant un brief textuel libre
- FR8 : Le système exécute les agents dans l'ordre séquentiel défini dans le YAML
- FR9 : Le système spawn un processus CLI (Claude Code ou Gemini CLI) par agent selon le champ `engine`, depuis le `project_path` défini
- FR10 : Le système injecte le system prompt de l'agent (inline ou via `system_prompt_file`) à chaque invocation CLI
- FR11 : Le système applique un timeout configurable par tâche et interrompt le processus CLI en cas de dépassement
- FR12 : Le système retente automatiquement une étape marquée `mandatory: true` après échec, dans la limite de `max_retries`
- FR13 : L'utilisateur peut annuler un run en cours

**Communication & Contexte Inter-agents (FR14–FR17)**
- FR14 : Le système maintient un fichier de contexte partagé (cycle `.md`) mis à jour après chaque étape
- FR15 : Chaque agent reçoit le fichier de contexte partagé en entrée, en plus de son brief de tâche
- FR16 : Le système capture et parse la sortie JSON structurée de chaque agent `{ step, status, output, next_action, errors }`
- FR17 : Le système transmet l'output JSON d'un agent comme entrée de contexte à l'agent suivant

**Monitoring Temps Réel (FR18–FR23)**
- FR18 : L'utilisateur peut voir l'état de chaque agent (idle / working / error / done) dans le diagramme, en temps réel
- FR19 : Le diagramme anime la transition entre agents lors d'un handoff
- FR20 : L'utilisateur reçoit des notifications de progression sous forme de bulles associées à l'agent actif
- FR21 : L'utilisateur peut consulter le log complet de la session dans une sidebar append-only, mise à jour en temps réel
- FR22 : L'utilisateur reçoit une alerte visuelle localisée sur le nœud concerné avec le détail de l'erreur en cas d'échec
- FR23 : L'utilisateur voit un récapitulatif de fin de run (agents exécutés, durée totale, statut, lien vers le dossier)

**Gestion des Erreurs & Résilience (FR24–FR27)**
- FR24 : Le système sauvegarde un checkpoint après chaque étape complétée avec succès
- FR25 : L'utilisateur peut relancer une étape en erreur depuis le dernier checkpoint sans rejouer les étapes précédentes
- FR26 : Le système libère proprement les ressources d'un processus CLI interrompu (timeout ou annulation)
- FR27 : Le système expose le message d'erreur et l'étape concernée dans l'alerte visible par l'utilisateur

**Artefacts & Traçabilité (FR28–FR31)**
- FR28 : Le système crée un dossier de run daté `runs/YYYY-MM-DD-HHMM/` pour chaque exécution
- FR29 : Le système génère et maintient un fichier `session.md` append-only contenant la trace complète du run
- FR30 : Le système sauvegarde les outputs de chaque agent dans le dossier run
- FR31 : L'utilisateur peut accéder à la liste des runs passés depuis l'interface

**Total FRs : 31**

### Exigences Non-Fonctionnelles

**Performance**
- NFR1 : Latence SSE → UI < 200ms en conditions localhost
- NFR2 : Spawn CLI + première sortie capturée < 5 secondes
- NFR3 : Rendu diagramme fluide jusqu'à 5 nœuds actifs

**Fiabilité**
- NFR4 : Zéro processus zombie après timeout, annulation ou erreur — cleanup systématique
- NFR5 : Client SSE reconnexion automatique en cas de déconnexion pendant un run actif
- NFR6 : Checkpoint écrit sur disque avant qu'une étape soit marquée complétée
- NFR7 : Sortie non-JSON d'un agent capturée et exposée — pas de crash silencieux du moteur

**Intégration**
- NFR8 : Support `claude-code` (flag `-p`) et `gemini-cli` (flag `-p`) comme engines headless interchangeables
- NFR9 : Contrat JSON `{ step, status, output, next_action, errors }` validé structurellement à chaque réponse agent
- NFR10 : Modification de syntaxe CLI headless → changement dans la couche driver uniquement, moteur core intact

**Sécurité**
- NFR11 : Processus CLI spawned exclusivement depuis le `project_path` défini dans le YAML
- NFR12 : Credentials, clés API et tokens jamais logués dans `session.md` ou les artefacts de run

**Total NFRs : 12**

### Contraintes & Hypothèses Notables

- Stack locale uniquement : Next.js + Laravel, localhost, Chrome desktop uniquement
- Pas de base de données — filesystem uniquement (`workflows/*.yaml`, `runs/`)
- SSE unidirectionnel (Laravel → Next.js), commandes utilisateur en REST
- Pas de WebSocket — SSE suffit pour le flux événementiel
- Phase 1 = pipeline séquentiel uniquement (pas de branches parallèles)

### Évaluation de Complétude PRD

PRD complet et bien structuré : 31 FRs couvrant 6 domaines fonctionnels, 12 NFRs couvrant performance, fiabilité, intégration et sécurité. Requirements numérotés et sans ambiguïté majeure. Critères de succès mesurables définis. Journeys utilisateur détaillés et cohérents avec les FRs.

---

## Validation de Couverture Epics

### Matrice de Couverture FR

| FR | Exigence (résumé) | Epic / Story | Statut |
|---|---|---|---|
| FR1 | Sélectionner workflow YAML | Epic 1 / Story 1.3–1.4 | ✅ Couvert |
| FR2 | Afficher `name` dans le sélecteur | Epic 1 / Story 1.4 | ✅ Couvert |
| FR3 | Recharger liste workflows | Epic 1 / Story 1.4 | ✅ Couvert |
| FR4 | Valider structure YAML avant run | Epic 1 / Story 1.3 | ✅ Couvert |
| FR5 | Visualiser diagramme avant run | Epic 1 / Story 1.5 | ✅ Couvert |
| FR6 | Configurer agents depuis YAML | Epic 1 / Story 1.3 | ✅ Couvert |
| FR7 | Lancer run via brief textuel | Epic 2 / Story 2.7a | ✅ Couvert |
| FR8 | Exécution séquentielle agents | Epic 2 / Story 2.1 | ✅ Couvert |
| FR9 | Spawn CLI par engine depuis project_path | Epic 2 / Story 2.1 | ✅ Couvert |
| FR10 | Injection system prompt | Epic 2 / Story 2.1 | ✅ Couvert |
| FR11 | Timeout configurable par tâche | Epic 2 / Story 2.2 | ✅ Couvert |
| FR12 | Retry auto étapes mandatory | Epic 3 / Story 3.2 | ✅ Couvert |
| FR13 | Annuler run en cours | Epic 2 / Story 2.2 | ✅ Couvert |
| FR14 | Fichier contexte partagé cycle .md | Epic 2 / Story 2.3 | ✅ Couvert |
| FR15 | Contexte partagé injecté à chaque agent | Epic 2 / Story 2.3 | ✅ Couvert |
| FR16 | Capture et parse sortie JSON agent | Epic 2 / Story 2.1 | ✅ Couvert |
| FR17 | Transmission output → agent suivant | Epic 2 / Story 2.1 | ✅ Couvert |
| FR18 | États agents temps réel dans diagramme | Epic 2 / Story 2.6 | ✅ Couvert |
| FR19 | Animation transition handoff | Epic 2 / Story 2.6 | ✅ Couvert |
| FR20 | Bulles SSE agent actif | Epic 2 / Story 2.7b | ✅ Couvert |
| FR21 | Sidebar log append-only temps réel | Epic 2 / Story 2.7b | ✅ Couvert |
| FR22 | Alerte visuelle localisée nœud erreur | Epic 3 / Story 3.3 | ✅ Couvert |
| FR23 | Modal récapitulatif fin de run | Epic 2 / Story 2.7b | ✅ Couvert |
| FR24 | Checkpoint après étape complétée | Epic 3 / Story 3.1 | ✅ Couvert |
| FR25 | Retry depuis dernier checkpoint | Epic 3 / Story 3.4 | ✅ Couvert |
| FR26 | Libération ressources CLI interrompu | Epic 3 / Story 3.4 + 2.2 | ✅ Couvert |
| FR27 | Exposition message erreur + étape | Epic 3 / Story 3.3 | ✅ Couvert |
| FR28 | Dossier run daté `runs/YYYY-MM-DD-HHMM/` | Epic 2 / Story 2.3 | ✅ Couvert |
| FR29 | session.md append-only | Epic 2 / Story 2.3 | ✅ Couvert |
| FR30 | Outputs agents sauvegardés | Epic 2 / Story 2.3 | ✅ Couvert |
| FR31 | Liste runs passés dans l'interface | Epic 4 / Story 4.1 | ✅ Couvert |

### Matrice de Couverture NFR

| NFR | Exigence (résumé) | Epic / Story | Statut |
|---|---|---|---|
| NFR1 | Latence SSE → UI < 200ms | Epic 2 / Story 2.4 | ✅ Couvert |
| NFR2 | Spawn CLI + 1ère sortie < 5s | Epic 2 / Story 2.2 | ✅ Couvert |
| NFR3 | Diagramme fluide ≤ 5 nœuds | Epic 1 / Story 1.5, Epic 2 / Story 2.6 | ✅ Couvert |
| NFR4 | Zéro zombie après timeout/annulation | Epic 2 / Story 2.2, Epic 3 | ✅ Couvert |
| NFR5 | SSE reconnexion automatique | Epic 2 / Story 2.5 | ✅ Couvert |
| NFR6 | Checkpoint write-before-complete | Epic 3 / Story 3.1 | ✅ Couvert |
| NFR7 | Sortie non-JSON capturée, pas de crash | Epic 2 / Story 2.1, Epic 3 | ✅ Couvert |
| NFR8 | Support claude-code + gemini-cli | Epic 2 / Story 2.1 | ✅ Couvert |
| NFR9 | Contrat JSON validé structurellement | Epic 2 / Story 2.1 | ✅ Couvert |
| NFR10 | Driver layer isolé | Epic 1 / Story 1.3 | ✅ Couvert |
| NFR11 | Spawn CLI depuis project_path uniquement | Epic 2 / Story 2.1 | ✅ Couvert |
| NFR12 | Aucun credential logué | Epic 2 / Story 2.3 | ✅ Couvert |

### Requirements Manquants

Aucun FR ni NFR manquant.

### Statistiques de Couverture

- **Total FRs PRD :** 31
- **FRs couverts en epics :** 31
- **Couverture FRs :** **100%** ✅
- **Total NFRs PRD :** 12
- **NFRs couverts en epics :** 12
- **Couverture NFRs :** **100%** ✅

---

## Alignement UX

### Statut Document UX

✅ `docs/planning-artifacts/ux-design-specification.md` — complet (direction C Cards Spacieux, 14 étapes, shadcn/ui + Tailwind)

### Alignement UX ↔ PRD

| FR PRD | Couverture UX | Statut |
|---|---|---|
| FR5 — Visualiser diagramme avant run | AgentCard en état `idle` avant lancement | ✅ Aligné |
| FR7 — Lancer run via brief | `LaunchBar` textarea + bouton "Lancer" | ✅ Aligné |
| FR13 — Annuler run | Bouton "Annuler" (destructif) dans LaunchBar état `running` | ✅ Aligné |
| FR3 — Recharger workflows | Bouton "Recharger" (outline) dans topbar | ✅ Aligné |
| FR18 — États agents temps réel | 4 états visuels distincts par AgentCard | ✅ Aligné |
| FR19 — Animation handoff | `PipelineConnector` avec transition `background-color` 300ms | ✅ Aligné |
| FR20 — Bulles SSE | `BubbleBox` variante `info` inline sous la card active | ✅ Aligné |
| FR21 — Sidebar log append-only | `ScrollArea` sidebar droite, toujours visible | ✅ Aligné |
| FR22 — Alerte localisée nœud erreur | `BubbleBox` variante `error` + `animate-pulse` sur card | ✅ Aligné |
| FR23 — Modal récapitulatif fin de run | `RunSummaryModal` (Dialog shadcn) — auto-déclenché | ✅ Aligné |
| FR25 — Retry depuis checkpoint | Bouton "Relancer cette étape" dans `BubbleBox error` | ✅ Aligné |
| FR1/FR2 — Sélecteur workflow | `Select` shadcn dans topbar avec `name` comme libellé | ✅ Aligné |
| **FR4 — Validation YAML : état d'erreur** | **Aucun état d'erreur de validation YAML défini dans l'UX** | ⚠️ Gap |
| **FR31 — Liste des runs passés** | **Aucun composant ou vue défini dans l'UX pour la liste des runs** | ⚠️ Gap |

### Alignement UX ↔ Architecture

| Décision Architecture | Couverture UX | Statut |
|---|---|---|
| Next.js SPA + App Router `use client` | UX mono-vue, pas de routing entre pages | ✅ Aligné |
| SSE Laravel → Zustand → React | `useSSEListener` + stores → BubbleBox + états cards | ✅ Aligné |
| Chrome desktop uniquement | UX responsive desktop-only, breakpoint `lg` unique | ✅ Aligné |
| shadcn/ui + Tailwind | Design system UX = shadcn + Tailwind + tokens sémantiques | ✅ Aligné |
| Zustand stores (workflow, run, agent status) | UX réactif aux changements d'état SSE | ✅ Aligné |
| Pas de base de données — filesystem | UX sans pagination, données en mémoire session | ✅ Aligné |
| Next.js proxy `/api/*` → `localhost:8000` | Pas de conflit avec l'UX (détail d'implémentation) | ✅ Aligné |
| **React Flow comme librairie diagramme** | **UX décrit un pipeline flex-col shadcn sans mentionner React Flow. Les stories Epic 1 n'en font pas référence non plus.** | ⚠️ Ambiguïté |

### Gaps et Avertissements

**⚠️ Gap 1 — FR4 : État d'erreur de validation YAML (mineur)**
L'UX ne définit pas ce qui s'affiche quand la validation YAML échoue au lancement. Le PRD spécifie HTTP 422 côté API, mais aucun composant UX n'est défini pour exposer ce message avant le démarrage du run. À résoudre : un message d'erreur inline dans la `LaunchBar` ou un banner dans la topbar.

**⚠️ Gap 2 — FR31 : Liste des runs passés (mineur)**
L'UX ne spécifie aucune vue ni composant pour la consultation des runs passés. Epic 4 / Story 4.1 couvre l'API (`GET /api/runs`), mais la story ne précise pas le composant UI. À résoudre : dropdown dans la topbar, panel latéral, ou vue dédiée.

**✅ Ambiguïté 3 — React Flow vs flex-col — RÉSOLUE**
Décision : **React Flow**. Les stories 1.5 et 2.6 ont été mises à jour pour expliciter l'usage de React Flow avec custom node type `agentCard` et custom edge type `pipelineConnector`. Les composants `AgentCard` et `PipelineConnector` sont rendus comme custom renderers dans React Flow — `data.status` est mis à jour via `useReactFlow().setNodes()` pour les transitions temps réel. Pipeline configuré read-only (`nodesDraggable={false}`, etc.).

---

## Revue de Qualité des Epics

### Checklist Epics — Valeur Utilisateur & Indépendance

| Epic | Titre | Valeur utilisateur | Indépendant | Verdict |
|---|---|---|---|---|
| Epic 1 | Visualiser et configurer son équipe d'agents | ✅ User peut voir son équipe | ✅ Standalone | ✅ Valide |
| Epic 2 | Lancer et observer un run complet | ✅ Core value MVP | ✅ Utilise Epic 1 | ✅ Valide |
| Epic 3 | Résilience et récupération d'erreurs | ✅ User peut récupérer d'un échec | ✅ Utilise Epic 1+2 | ✅ Valide |
| Epic 4 | Historique des runs | ✅ User accède à l'historique | ✅ Utilise artefacts Epic 2 | ✅ Valide |

Aucun epic technique déguisé. Tous les epics décrivent une valeur utilisateur claire.

### Analyse Détaillée par Story

**Epic 1 — Stories**

| Story | Valeur user | Indépendance | ACs (GWT) | Verdict |
|---|---|---|---|---|
| 1.1 — Init Next.js + Laravel | 🟡 Dev setup (greenfield) | ✅ Standalone | ✅ | ✅ Acceptable |
| 1.2 — Design system + layout | 🟡 Dev fondation | ✅ Dépend 1.1 | ✅ | ✅ Acceptable |
| 1.3 — API YAML Laravel | 🟡 Dev API | ✅ Dépend 1.1 | ✅ | ✅ Acceptable |
| 1.4 — Sélecteur + rechargement | ✅ User sélectionne son workflow | ✅ Dépend 1.3 | ✅ | ✅ Valide |
| 1.5 — Diagramme statique | ✅ User voit son équipe | ✅ Dépend 1.4 | ✅ | ✅ Valide |

**Epic 2 — Stories**

| Story | Valeur user | Indépendance | ACs (GWT) | Verdict |
|---|---|---|---|---|
| 2.1 — Moteur spawn CLI | ✅ Run s'exécute | ✅ Dépend Epic 1 | ✅ | ✅ Valide |
| 2.2 — Timeout + annulation | ✅ Contrôle du run | ✅ Dépend 2.1 | ✅ | ✅ Valide |
| 2.3 — Contexte + artefacts | ✅ Traçabilité run | ✅ Dépend 2.1 | ✅ | ✅ Valide |
| 2.4 — Stream SSE Laravel | ✅ Feedback temps réel | ✅ Dépend 2.1 | ✅ | ✅ Valide |
| 2.5 — Client SSE + Zustand | ✅ UI réactive | ✅ Dépend 2.4 | ✅ | ✅ Valide |
| 2.6 — Diagramme animé | ✅ Visualisation handoff | ✅ Dépend 2.5 | ✅ | ✅ Valide |
| 2.7 — Bulles + sidebar + LaunchBar | ✅ Feedback complet | ✅ Dépend 2.5 | ✅ | ⚠️ Trop dense |

**Epic 3 — Stories**

| Story | Valeur user | Indépendance | ACs (GWT) | Verdict |
|---|---|---|---|---|
| 3.1 — Checkpoint écriture/lecture | ✅ Enable retry | ✅ Dépend Epic 2 | ✅ | ✅ Valide |
| 3.2 — Retry auto mandatory | ✅ Récupération auto | ✅ Dépend 3.1 | ✅ | ✅ Valide |
| 3.3 — Alerte localisée + BubbleBox error | ✅ User voit l'erreur | ✅ Dépend 2.5 | ✅ | ✅ Valide |
| 3.4 — Retry manuel checkpoint | ✅ User reprend au bon point | ✅ Dépend 3.1 + 3.3 | ✅ | ✅ Valide |

**Epic 4 — Stories**

| Story | Valeur user | Indépendance | ACs (GWT) | Verdict |
|---|---|---|---|---|
| 4.1 — Liste runs passés | ✅ Accès historique | ✅ Dépend artefacts Epic 2 | ✅ (API) / ⚠️ (UI) | ⚠️ AC incomplet |

### Issues de Qualité par Sévérité

**🔴 Violations Critiques**

Aucune.

**🟠 Issues Majeures**

**Issue 1 — Story 2.7 : Trop dense (3 features en une story)**
Story 2.7 regroupe `BubbleBox SSE`, `sidebar de log` et `LaunchBar` dans une seule story. Ces 3 composants sont implémentés indépendamment — les regrouper crée un risque de blocage de l'Epic 2 si l'un des trois s'avère plus complexe qu'estimé.

*Recommandation :* Scinder en :
- **Story 2.7a** — `LaunchBar` : barre de lancement (ready / running / disabled), POST `/api/runs`, état disabled si aucun workflow
- **Story 2.7b** — Bulles SSE + sidebar : `BubbleBox` info/success, sidebar `.md` append-only, `RunSummaryModal`

**🟡 Issues Mineures**

**Issue 2 — Story 1.3 : Référence avant à Epic 2**
L'AC de Story 1.3 indique "squelettes ClaudeDriver et GeminiDriver existent (corps à compléter en Epic 2)". Ce forward reference est documenté et intentionnel — acceptable, mais doit être traçable. Le dev implémentant Story 1.3 doit savoir explicitement que ces drivers seront incomplets jusqu'à Story 2.1.

*Recommandation :* Ajouter un commentaire `// TODO Epic 2 - Story 2.1` dans le corps des méthodes `execute()` des drivers lors de l'implémentation Story 1.3.

**Issue 3 — Story 4.1 : AC UI incomplet**
Story 4.1 définit l'API `GET /api/runs` et le contenu affiché, mais ne précise pas le composant UI ni son emplacement dans l'interface (topbar dropdown ? panel latéral ? vue dédiée ?). Ce flou est directement lié au Gap 2 UX identifié précédemment (FR31).

*Recommandation :* Clarifier le composant UI dans la story avant implémentation. Décision à prendre : topbar dropdown (simple, cohérent avec SPA mono-vue) ou vue séparée (plus de travail, hors scope MVP ?).

**Issue 4 — Stories 1.1 et 1.2 : Stories de setup développeur**
Ces stories n'ont pas de valeur utilisateur directe (setup technique pur). C'est acceptable et attendu pour un greenfield project selon les best practices, mais elles ne constituent pas de la valeur livrable au sens strict.

*Recommandation :* Les laisser telles quelles — c'est le bon pattern pour l'initialisation greenfield.

### Analyse des Dépendances — Conformité

```
Story 1.1 (base)
  └── Story 1.2 (design system) ✅
  └── Story 1.3 (API YAML) ✅
       └── Story 1.4 (sélecteur) ✅
            └── Story 1.5 (diagramme statique) ✅
                 └── Story 2.1 (moteur spawn CLI) ✅
                      └── Story 2.2 (timeout/annulation) ✅
                      └── Story 2.3 (contexte/artefacts) ✅
                      └── Story 2.4 (SSE Laravel) ✅
                           └── Story 2.5 (SSE client + Zustand) ✅
                                └── Story 2.6 (diagramme animé) ✅
                                └── Story 2.7a (LaunchBar) ✅
                                └── Story 2.7b (bulles + sidebar + modal) ✅
                                └── Story 3.3 (alerte localisée) ✅
                 └── Story 3.1 (checkpoint) ✅
                      └── Story 3.2 (retry auto) ✅
                      └── Story 3.4 (retry manuel) ← dépend aussi 3.3 ✅
     └── Story 4.1 (historique runs) ✅ — dépend des artefacts Epic 2
```

Chaîne de dépendances propre. Aucune référence circulaire. Aucune dépendance forward non documentée.

### Conformité Base de Données

Pas de base de données dans ce projet (filesystem uniquement). Cette vérification est N/A.

---

## Résumé et Recommandations

### Statut Global de Readiness

**✅ READY — Toutes les issues résolues**

Les fondations (PRD, Architecture, UX) sont solides et bien alignées. La couverture FR/NFR est complète à 100%. Toutes les issues identifiées ont été résolues. Le projet est prêt à implémenter.

### Issues par Sévérité

**🔴 Bloquant (à résoudre avant Story 1.5)**

**~~Issue A — Ambiguïté React Flow vs flex-col~~ ✅ Résolue**
Décision : **React Flow**. Stories 1.5 et 2.6 mises à jour — custom node `agentCard`, custom edge `pipelineConnector`, pipeline read-only, mise à jour via `useReactFlow().setNodes()`.

**🟠 Majeur (à traiter avant de démarrer Epic 2)**

**~~Issue B — Story 2.7 trop dense~~ ✅ Résolue**
Story scindée en :
- **Story 2.7a** — LaunchBar (ready/running/disabled, POST `/api/runs`, annulation)
- **Story 2.7b** — Bulles SSE + sidebar de log + RunSummaryModal

**~~🟡 Mineur (à traiter pendant l'implémentation)~~**

**~~Issue C — FR4 : État d'erreur YAML non défini dans l'UX~~ ✅ Résolue**
AC ajouté dans Story 2.7a : message d'erreur inline dans la `LaunchBar` au-dessus du `Textarea` si HTTP 422 `YAML_INVALID` — disparaît au changement de workflow ou au prochain lancer.

**~~Issue D — FR31 : Composant UI liste runs non spécifié~~ ✅ Résolue**
Décision : `Sheet` shadcn déclenché depuis un bouton "Historique" dans la topbar (outline, à droite de "Recharger"). Story 4.1 mise à jour avec ACs UI complets : liste triée par date, badge statut, lien dossier, état vide, disabled pendant un run actif.

**~~Issue E — Story 1.3 : Forward reference Epic 2~~ ✅ Résolue**
AC mis à jour dans Story 1.3 : les méthodes `execute()` et `kill()` des drivers lèvent `\RuntimeException('Not implemented')` avec commentaire `// TODO Epic 2 - Story 2.1`.

### Récapitulatif des Issues

| # | Sévérité | Catégorie | Description | Action |
|---|---|---|---|---|
| A | ✅ Résolu | Architecture ↔ UX | React Flow — décision actée, stories 1.5 + 2.6 mises à jour | — |
| B | ✅ Résolu | Qualité Epic | Story 2.7 scindée en 2.7a (LaunchBar) + 2.7b (Bulles + sidebar + modal) | — |
| C | ✅ Résolu | UX Gap | AC erreur YAML ajouté dans Story 2.7a (LaunchBar, inline) | — |
| D | ✅ Résolu | UX Gap | Sheet "Historique" défini dans Story 4.1, ACs UI complets | — |
| E | ✅ Résolu | Story Quality | Drivers scaffoldés avec RuntimeException + TODO Story 2.1 | — |

**Total issues : 5 — 5 résolues ✅**

### Recommandations — Prochaines Étapes

1. ~~Résoudre Issue A~~ ✅
2. ~~Résoudre Issue B~~ ✅
3. ~~Résoudre Issues C, D, E~~ ✅
4. **Démarrer l'implémentation** — Story 1.1 (initialisation Next.js + Laravel).

### Note Finale

Ce rapport a identifié **5 issues** (1 bloquante, 1 majeure, 3 mineures), toutes résolues. La couverture FR/NFR est complète (100%), les epics sont bien découpés, les dépendances sont propres et la chaîne d'implémentation est claire. Le projet est en ordre de marche.

---

*Assessment généré le 2026-04-03 — xu-workflow*
