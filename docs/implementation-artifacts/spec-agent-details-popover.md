# Spec: Affichage du détail d'un Agent dans un Popover

## Objectif
Permettre à l'utilisateur de consulter la configuration complète d'un agent (définie dans le fichier YAML) directement depuis l'interface, sans avoir à ouvrir le fichier source.

## UX / UI
- **Déclencheur** : Un bouton "Info" (icône `Info` de `lucide-react`) ajouté à chaque `AgentSidebarItem`.
- **Positionnement** : Le bouton est situé à droite du nom de l'agent dans la barre latérale.
- **Comportement** :
    - Au clic sur le bouton, un Popover (bulle flottante) s'affiche.
    - Le Popover se ferme si l'utilisateur clique en dehors (click-out).
    - Le Popover affiche les détails de l'agent de manière lisible.

## Détails techniques

### 1. Types Frontend
Mettre à jour `frontend/src/types/workflow.types.ts` pour inclure tous les champs possibles d'un agent provenant du YAML :
- `mandatory?: boolean`
- `max_retries?: number`
- `skippable?: boolean`
- `interactive?: boolean`
- `system_prompt?: string`
- `loop?: { over: string, as: string }`

### 2. Composant UI Popover
Si non présent, créer un composant `frontend/src/components/ui/popover.tsx` en utilisant `@base-ui/react/popover`.

### 3. AgentSidebarItem
Modifier `frontend/src/components/AgentSidebarItem.tsx` :
- Recevoir l'objet `Agent` complet (ou les props supplémentaires).
- Ajouter le bouton déclencheur.
- Intégrer le composant Popover.

### 4. Contenu du Popover
Le contenu doit être formaté pour être agréable à lire :
- **Titre** : ID de l'agent.
- **Grille de propriétés** : Engine, Timeout, Mandatory, etc.
- **System Prompt** : Affiché dans un bloc de texte (éventuellement tronqué ou scrollable si trop long).
- **Steps** : Liste des étapes.

## Exemple de structure du Popover
```
+------------------------------------------+
| Agent: [pm]                              |
+------------------------------------------+
| Engine: gemini-cli                       |
| Timeout: 600s                            |
| Mandatory: Yes         Interactive: Yes  |
+------------------------------------------+
| System Prompt:                           |
| "Tu es un PM. Si la demande..."          |
+------------------------------------------+
| Steps:                                   |
| - Étape 1 — PM                           |
+------------------------------------------+
```

## Validation
- Vérifier que le bouton est cliquable.
- Vérifier que les données affichées correspondent au YAML.
- Vérifier que le popover se ferme au clic extérieur.
