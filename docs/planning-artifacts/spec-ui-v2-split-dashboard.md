# Spécification UI : XuMaestro V2 "Split Dashboard"

## 1. Vision et Objectifs
L'interface abandonne le paradigme du diagramme de flux (nœuds mobiles) au profit d'un **tableau de bord à double panneau fixe**. L'objectif est de fournir une lecture linéaire, prévisible et sans distraction (pas de zoom, pas de drag), où le terminal est l'acteur principal.

---

## 2. Design System (OLED Dark Mode)

| Élément | Couleur Hex | Usage |
| :--- | :--- | :--- |
| **Fond (Background)** | `#000000` | Noir pur (OLED) |
| **Surface (Card/Sidebar)** | `#09090b` | Gris très sombre pour détacher les zones |
| **Bordure (Border)** | `#27272a` | Délimitation subtile des panneaux |
| **Texte Primaire** | `#fafafa` | Titres et logs actifs |
| **Texte Secondaire** | `#71717a` | Logs passés, métadonnées |
| **Accent : Working** | `#3b82f6` | Bleu vif pour l'agent actif |
| **Accent : Success** | `#22c55e` | Vert pour les étapes terminées |
| **Accent : Error** | `#ef4444` | Rouge vif pour les blocages |

**Typographie :**
- **Interface :** `Inter` ou `Geist` (Sans-serif)
- **Terminal :** `JetBrains Mono` (Monospaced) - Taille 13px.

---

## 3. Layout et Structure des Scrolls

### A. Sidebar Gauche (L'Équipe / Pipeline)
- **Largeur :** 280px à 320px (Fixe).
- **Scroll :** Vertical indépendant (`overflow-y: auto`).
- **Contenu :** Liste statique des agents définis dans le YAML.

### B. Panneau Droit (Le Centre de Commande)
- **Largeur :** Flexible (`flex-1`).
- **Scroll :** La zone centrale de log possède son propre scroll vertical. 
- **Sticky Terminal :** Le terminal "colle" au bas de la zone lors de l'arrivée de nouveaux logs (Auto-scroll).

---

## 4. Gestion des Événements et États

| État | Visuel Sidebar | Comportement Terminal |
| :--- | :--- | :--- |
| **Pending** | Opacité 50%, icône `○` | Vide ou "Waiting..." |
| **Working** | Bordure bleue + Glow, icône `⚙` | Flux de logs actif, Auto-scroll ON |
| **Success** | Bordure verte, icône `✓` | Logs grisés, résumé de fin affiché |
| **Error** | Bordure rouge Pulse, icône `✗` | Arrêt du flux, affichage du bloc d'erreur |

### Gestion du Retry
En cas d'erreur, le bouton **[RETRY STEP]** apparaît **à l'intérieur du terminal**, juste après le message d'erreur. Cela évite de chercher une action dans un menu contextuel ou une barre d'outils éloignée.

---

## 5. Interaction Question / Réponse

L'interaction utilisateur est traitée comme une entrée terminale standard :
1. L'agent s'arrête et affiche sa demande dans le log.
2. Un champ de saisie (`Input`) apparaît **directement à la suite du texte de l'agent**.
3. L'utilisateur répond, la réponse s'affiche comme une commande `[USER]: <réponse>` dans le log.
4. Le flux reprend naturellement.

---

## 6. Structure Technique (Next.js / Tailwind)

- **Layout :** `flex h-screen overflow-hidden`
- **Sidebar :** `w-80 border-r bg-zinc-950 overflow-y-auto`
- **Main :** `flex-1 flex flex-col bg-black`
- **LogArea :** `flex-1 overflow-y-auto font-mono p-4`
- **ActionArea :** `h-20 border-t bg-zinc-950 p-4` (Contient le brief de départ ou les actions globales)
