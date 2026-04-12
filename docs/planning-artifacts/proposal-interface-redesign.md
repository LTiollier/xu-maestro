# Proposition de Refonte : Interface "Vision Pro" pour xu-workflow

## 1. Vision Design : L'Expérience "Apple Desktop"

L'objectif est de transformer xu-workflow en un outil ultra-épuré, calme et précis, s'inspirant des standards de design d'Apple (macOS/VisionOS).

### A. Le Canvas Automatisé (Auto-Layout)
Puisque tu n'as pas besoin de déplacer les agents manuellement, nous allons implémenter un **Auto-Layout intelligent**.
*   **Capacité :** Support fluide jusqu'à 10 agents.
*   **Navigation :** Le canvas est 2D mais l'agencement est géré par l'algorithme (ex: `Dagre` ou `Elk`).
*   **Esthétique :** Fond `zinc-950` avec un gradient radial très subtil au centre. Pas de grille visible, juste un espace de travail infini et pur.

### B. Indicateurs de Progression (Réponse à ta question)
Au lieu de faire défiler des logs textuels bruts, nous utiliserons des **indicateurs visuels sémantiques** :
1.  **L'Anneau d'Activité (Activity Ring) :** Un cercle de progression autour de l'avatar de l'agent qui se remplit au fur et à mesure des étapes.
2.  **Micro-Micro-Logs (Glimpse) :** Une seule ligne de texte en bas de la card, montrant uniquement l'action *actuelle* (ex: "Analyse du fichier index.ts...").
3.  **Pulse Rythmique :** Une lueur (glow) douce qui pulse sur l'agent actif, synchronisée avec les battements de l'exécution SSE.
4.  **Handoff Animé :** Une "étincelle" lumineuse qui parcourt le connecteur entre l'Agent A et l'Agent B lors du passage de relais.

### C. La Card Agent "Glassmorphism"
*   **Matériau :** Fond translucide avec un flou important (`backdrop-blur-xl`).
*   **Bordures :** Ligne de 1px ultra-fine, légèrement plus lumineuse en haut pour simuler une source de lumière.
*   **Ombres :** Ombres portées larges et très douces pour donner de la profondeur (Z-axis).

---

## 2. Architecture de l'Information

| Zone | Rôle | Style |
|---|---|---|
| **Centre (Canvas)** | Workflow & Exécution | Immersif, épuré, focus sur l'actif |
| **Haut (Status Bar)** | Sélection & Stats globales | Flottante, minimaliste |
| **Bas (Input Bar)** | Brief & Lancement | Textarea qui s'étend, bouton "Play" discret |
| **Côté (Drawer Log)** | Détails techniques | Masqué par défaut, s'ouvre au clic sur un agent |

---

## 3. Choix Techniques Validés

*   **Framework Graph :** `React Flow` (pour la robustesse du canvas et du zoom).
*   **Animations :** `Framer Motion` (pour les physiques de ressort "Apple-like").
*   **Composants :** `shadcn/ui` (version ultra-customisée avec des tokens de transparence).

---

## Prochaines étapes
1.  **Prototype `AgentCard` Apple-Style :** Je vais créer un composant React de démonstration avec le Glassmorphism et l'anneau de progression.
2.  **Mise à jour du `WorkflowStore` :** Préparer les données pour le mode "Focus".

