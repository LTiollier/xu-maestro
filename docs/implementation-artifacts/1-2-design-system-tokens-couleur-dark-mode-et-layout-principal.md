# Story 1.2 : Design system — tokens couleur, dark mode et layout principal

Status: done
Epic: 1
Story: 2
Date: 2026-04-04

## Story

As a développeur,
I want un design system dark mode avec les tokens couleur sémantiques et le layout 2 colonnes,
So that tous les composants UI qui suivent utilisent des fondations visuelles cohérentes.

## Acceptance Criteria

1. **Given** le projet frontend initialisé — **When** les tokens sont définis — **Then** `globals.css` expose les classes Tailwind `bg-agent-idle`, `bg-agent-working`, `bg-agent-done`, `bg-agent-error` via `@theme inline` dans le bloc existant
2. **Given** le dark mode est actif (`className="dark"` sur `<html>`) — **When** la page se charge — **Then** la palette est exactement : background `zinc-950`/`zinc-900`, surface `zinc-800`, border `zinc-700`, text primary `zinc-100`, text secondary `zinc-400`, edge active `blue-400`
3. **Given** le projet frontend initialisé — **When** `page.tsx` est rendu — **Then** le layout affiche : topbar fixe (h-14) + zone centrale flex (diagramme + sidebar) + LaunchBar fixe (h-20 en bas)
4. **Given** le layout 2 colonnes — **When** la fenêtre est ≥ 1024px — **Then** la sidebar (`w-80`) est visible à droite, séparée par une bordure ; en dessous de 1024px la sidebar est masquée et le diagramme prend toute la largeur
5. **Given** les composants shadcn installés — **When** les tokens couleur sont actifs — **Then** le contraste WCAG AA (≥ 4.5:1) est vérifié manuellement sur `zinc-100` sur `zinc-950`

## Tasks / Subtasks

- [x] **T1 — Ajouter les tokens agent dans globals.css** (AC: 1)
  - [x] Ouvrir `frontend/src/app/globals.css`
  - [x] Dans le bloc `@theme inline { ... }` existant, ajouter les 5 variables agent AVANT la dernière accolade (voir §Tokens)
  - [x] Vérifier que `bg-agent-idle`, `text-agent-working`, `border-agent-done`, `border-agent-error` fonctionnent en compilation

- [x] **T2 — Aligner la palette dark mode zinc** (AC: 2)
  - [x] Dans `globals.css`, modifier la section `.dark { ... }` pour aligner exactement les valeurs zinc (voir §Palette dark mode)
  - [x] S'assurer que `--background`, `--card`, `--border`, `--foreground`, `--muted-foreground` correspondent exactement aux valeurs zinc cibles

- [x] **T3 — Créer le layout 2 colonnes dans page.tsx** (AC: 3, 4)
  - [x] Remplacer entièrement le contenu de `frontend/src/app/page.tsx` par le layout décrit dans §Layout
  - [x] Le layout doit être fonctionnel (se compile) avec des zones placeholder commentées
  - [x] Vérifier `hidden lg:flex` sur la sidebar pour le responsive

- [x] **T4 — Vérification build** (AC: 1–5)
  - [x] `npm run build` passe sans erreur TypeScript
  - [x] `npm run dev` : vérifier visuellement la palette dark mode et le layout 2 colonnes dans le navigateur

### Review Findings (2026-04-04)

- [x] [Review][Patch] `--sidebar-primary-foreground` non aligné — reste à `oklch(0.985 0 0)` alors que tous les autres foregrounds ont été mis à jour à `oklch(0.961 0 0)` (zinc-100) [frontend/src/app/globals.css — section .dark]
- [x] [Review][Defer] `h-full` sur le wrapper page.tsx — `flex-1` serait plus correct sémantiquement pour un enfant flex ; `h-full` fonctionne en pratique mais `flex-1` est à préférer lors du prochain refactor de page.tsx [frontend/src/app/page.tsx] — deferred, pre-existing
- [x] [Review][Defer] `--border`/`--input` passés de transparent à opaque — intentionnel selon spec (zinc-700), peut légèrement affecter les composants shadcn avec opacité composited (`/30` etc.). Surveiller lors de Story 1.3+ [frontend/src/app/globals.css] — deferred, pre-existing
- [x] [Review][Defer] Tokens agent sans variantes dark mode — intentionnel, ce sont des couleurs sémantiques d'état (pas de fond). Vérifier le contraste lors de Story 1.5 (AgentCard) [frontend/src/app/globals.css] — deferred, pre-existing
- [x] [Review][Defer] Sidebar masquée < 1024px sans affordance d'accès — sera adressé en Story 2.7b [frontend/src/app/page.tsx] — deferred, pre-existing

## Dev Notes

---

### §CRITIQUE — État réel du projet (lire avant tout)

**Tailwind v4 — PAS de tailwind.config.ts**
La Story 1.1 indique dans ses completion notes que les tokens `agent-*` ont été ajoutés dans globals.css. **Ce n'est pas le cas dans le fichier actuel** — le globals.css ne contient aucun token `agent-*`. La Story 1.2 doit les créer.

Fichier `frontend/tailwind.config.ts` : **n'existe pas**. Tailwind v4 = configuration exclusive via `@theme inline` dans `globals.css`. **Ne jamais créer de tailwind.config.ts.**

**État actuel de globals.css :**
- Bloc `@theme inline { }` existant (lignes 7-45 env.) — variables shadcn oklch
- Section `:root { }` — palette light mode
- Section `.dark { }` — palette dark mode oklch
- Section `@layer base { }` — styles de base

**État actuel de layout.tsx :**
- `<html lang="fr" className="... dark h-full antialiased">` — dark mode déjà actif ✅
- `<body className="min-h-full flex flex-col">` — flex column ✅
- `<TooltipProvider>` wrapping children ✅

**État actuel de page.tsx :**
- Page Next.js par défaut avec Image, liens Vercel/docs
- À remplacer **entièrement** par le layout 2 colonnes

---

### §Tokens — Ajout dans globals.css @theme inline

**Emplacement exact :** dans le bloc `@theme inline { ... }` existant, ajouter avant la fermeture `}` :

```css
/* Tokens agent sémantiques */
--color-agent-idle: #71717a;       /* zinc-500 — agent en attente */
--color-agent-working: #3b82f6;    /* blue-500 — agent actif */
--color-agent-done: #10b981;       /* emerald-500 — agent terminé */
--color-agent-error: #ef4444;      /* red-500 — agent en erreur */
--color-agent-edge-active: #60a5fa; /* blue-400 — connecteur activé lors d'un handoff */
```

**Utilisation dans les composants :**
```tsx
// ✅ Classes générées automatiquement par Tailwind v4
<div className="bg-agent-idle" />
<div className="bg-agent-working" />
<div className="border-agent-done" />
<div className="text-agent-error" />
<div className="bg-agent-edge-active" />
```

**Règle absolue (UX-DR7) :** Ces tokens sont les SEULS à utiliser pour les états d'agents dans toute l'UI — jamais de couleurs hard-codées comme `#3b82f6` ou `text-blue-500` directement dans les composants.

---

### §Palette dark mode — Alignement zinc dans globals.css

La section `.dark { }` actuelle utilise des valeurs oklch générées par shadcn. Remplacer les variables clés pour aligner exactement avec la spec zinc :

```css
.dark {
  /* Backgrounds */
  --background: oklch(0.145 0 0);     /* zinc-950 ≈ #09090b — fond app */
  --card: oklch(0.21 0 0);            /* zinc-900 ≈ #18181b — surface cards */
  --popover: oklch(0.21 0 0);         /* zinc-900 */
  
  /* Surface (panels, sidebar) */
  --secondary: oklch(0.269 0 0);      /* zinc-800 ≈ #27272a — surface secondaire */
  --muted: oklch(0.269 0 0);          /* zinc-800 */
  
  /* Borders */
  --border: oklch(0.32 0 0);          /* zinc-700 ≈ #3f3f46 — séparateurs */
  --input: oklch(0.32 0 0);           /* zinc-700 */
  
  /* Textes */
  --foreground: oklch(0.961 0 0);     /* zinc-100 ≈ #f4f4f5 — texte principal */
  --card-foreground: oklch(0.961 0 0);
  --popover-foreground: oklch(0.961 0 0);
  --secondary-foreground: oklch(0.961 0 0);
  --muted-foreground: oklch(0.622 0 0); /* zinc-400 ≈ #a1a1aa — texte secondaire */
  
  /* Autres — conserver valeurs shadcn */
  --primary: oklch(0.922 0 0);
  --primary-foreground: oklch(0.205 0 0);
  --accent: oklch(0.269 0 0);
  --accent-foreground: oklch(0.985 0 0);
  --destructive: oklch(0.704 0.191 22.216);
  --ring: oklch(0.556 0 0);
  /* sidebar vars — conserver valeurs shadcn existantes */
}
```

**Contraste WCAG AA :** `zinc-100` (#f4f4f5) sur `zinc-950` (#09090b) = ratio ~19:1 ✅ (bien au-dessus de 4.5:1)

---

### §Layout 2 colonnes — Contenu exact de page.tsx

Remplacer **tout** le contenu de `frontend/src/app/page.tsx` par :

```tsx
export default function Home() {
  return (
    <div className="flex flex-col h-full bg-zinc-950">
      {/* Topbar — h-14, toujours visible */}
      <header className="h-14 shrink-0 border-b border-zinc-700 bg-zinc-900 flex items-center px-4 gap-3">
        {/* Story 1.4 : WorkflowSelector */}
        <span className="text-zinc-400 text-sm">Sélecteur de workflow (Story 1.4)</span>
      </header>

      {/* Zone principale : diagramme + sidebar */}
      <div className="flex flex-1 overflow-hidden">
        {/* Zone diagramme — flex-1, max-w-2xl centré à > 1600px */}
        <main className="flex-1 overflow-auto p-4 flex flex-col items-center">
          {/* Story 1.5 : AgentDiagram */}
          <div className="w-full max-w-2xl">
            <p className="text-zinc-400 text-sm">Diagramme agents (Story 1.5)</p>
          </div>
        </main>

        {/* Sidebar — masquée en dessous de lg (1024px) */}
        <aside className="hidden lg:flex w-80 shrink-0 border-l border-zinc-700 bg-zinc-900 flex-col overflow-hidden">
          {/* Story 2.7b : RunSidebar (log .md append-only) */}
          <div className="flex-1 overflow-auto p-3">
            <p className="text-zinc-400 text-sm">Sidebar log (Story 2.7b)</p>
          </div>
        </aside>
      </div>

      {/* LaunchBar — h-20, fixée en bas */}
      <footer className="h-20 shrink-0 border-t border-zinc-700 bg-zinc-900 flex items-center px-4 gap-3">
        {/* Story 2.7a : LaunchBar */}
        <p className="text-zinc-400 text-sm">LaunchBar (Story 2.7a)</p>
      </footer>
    </div>
  );
}
```

**Points critiques du layout :**
- `h-full` sur le wrapper (pas `h-screen`) — `layout.tsx` a déjà `h-full` sur `<html>` et `min-h-full` sur `<body>`
- `shrink-0` sur header et footer pour qu'ils ne se compriment pas
- `flex-1 overflow-hidden` sur la zone centrale pour qu'elle occupe l'espace restant sans déborder
- `hidden lg:flex` sur la sidebar — la class `flex` est nécessaire car la sidebar a `flex-col` pour son contenu interne
- `max-w-2xl` sur le pipeline de cards (uniquement au-delà de 1600px grâce à `flex-1` qui s'arrête à 2xl)

**Responsive :**
- < 1024px : sidebar masquée, diagramme pleine largeur
- 1024px–1600px : layout nominal diagramme + sidebar
- > 1600px : pipeline de cards centré `max-w-2xl` dans la zone diagramme

---

### §Guardrails — Erreurs à ne pas commettre

| ❌ Interdit | ✅ Correct |
|---|---|
| Créer `tailwind.config.ts` | Tailwind v4 = `@theme inline` dans `globals.css` uniquement |
| `text-blue-500` pour un état agent | `text-agent-working` (token sémantique) |
| `h-screen` sur le layout | `h-full` (html a déjà `h-full` via layout.tsx) |
| `lg:block` sur la sidebar | `lg:flex` (car la sidebar a des enfants flex-col) |
| Modifier `layout.tsx` | layout.tsx est déjà correct, ne pas toucher |
| Supprimer `TooltipProvider` de layout.tsx | Wrapping body dans TooltipProvider — requis pour shadcn |
| Couleurs hard-codées dans les styles | Utiliser les classes Tailwind générées depuis les tokens |
| Importer `Image` de next/image dans page.tsx | Ne pas importer ce qui n'est plus utilisé |
| `'use client'` inutile sur page.tsx | page.tsx = Server Component par défaut (pas d'état local à ce stade) |

---

### §Contexte Story 1.1 — Apprentissages applicables

**Tailwind v4 @theme inline :** le format `--color-agent-*` dans `@theme inline` génère automatiquement les classes `bg-agent-*`, `text-agent-*`, `border-agent-*`. Pas besoin de configuration supplémentaire.

**shadcn/ui dark mode :** via `@custom-variant dark (&:is(.dark *))` dans globals.css + `className="dark"` sur `<html>`. La classe `.dark` est déjà sur `<html>` dans `layout.tsx` — ne pas supprimer.

**Dossier src/** : toute la logique frontend est dans `frontend/src/`. Les dossiers `stores/`, `hooks/`, `types/`, `lib/` existent mais sont vides — peuplés à partir de cette story et des suivantes.

**Review feedback Story 1.1 à ne pas répéter :**
- Ne pas laisser de variables d'environnement inutilisées (ex: `NEXT_PUBLIC_API_URL` qui n'était pas utilisée dans next.config.ts)
- Ne pas déplacer les dépendances dev dans dependencies (shadcn avait été mis dans dependencies)
- Vérifier les chemins `base_path()` (erreur de chemin relative en dehors du projet dans 1.1)

---

### §Structure finale attendue après Story 1.2

```
frontend/src/app/
├── globals.css       ← @theme inline avec tokens agent-* ajoutés, .dark palette zinc alignée
├── layout.tsx        ← inchangé (dark, TooltipProvider, Geist)
└── page.tsx          ← layout 2 colonnes (topbar + main + sidebar + footer)
```

Aucun nouveau fichier à créer. Aucune dépendance à installer.

---

### §Vérification build

```bash
cd frontend
npm run build   # Doit réussir sans erreur TypeScript
npm run dev     # Vérifier visuellement : fond zinc-950, layout 2 colonnes, sidebar visible à ≥ 1024px
```

**Vérification manuelle dans le navigateur :**
- Fond de page : `zinc-950` (#09090b — quasi noir)
- Topbar et sidebar/footer : `zinc-900` (#18181b — gris très sombre)
- Bordures : `zinc-700` (#3f3f46 — gris moyen)
- À < 1024px : sidebar masquée, diagramme plein écran

---

### Références

- [Source: docs/planning-artifacts/epics.md#Story-1.2] — AC et user story
- [Source: docs/planning-artifacts/ux-design-specification.md#UX-DR7] — tokens agent sémantiques
- [Source: docs/planning-artifacts/ux-design-specification.md#UX-DR8] — palette dark mode zinc
- [Source: docs/planning-artifacts/ux-design-specification.md#UX-DR9] — layout 2 colonnes responsive
- [Source: docs/planning-artifacts/ux-design-specification.md#UX-DR11] — contraste WCAG AA
- [Source: docs/implementation-artifacts/1-1-initialisation-des-projets-next-js-et-laravel.md] — apprentissages Story 1.1

## Dev Agent Record

### Agent Model Used

claude-sonnet-4-6

### Debug Log References

- Les tokens `agent-idle/working/done/error` étaient déjà présents dans globals.css (Story 1.1 les avait bien créés — la story 1.2 a seulement ajouté `--color-agent-edge-active` qui manquait)
- La palette dark mode `.dark { }` utilisait `--border: oklch(1 0 0 / 10%)` (white transparent) au lieu de zinc-700 solide — corrigé
- `--muted-foreground` était à `0.708` (zinc-300) au lieu de `0.556` (zinc-400) — corrigé
- `--card` était à `0.205` (zinc-900) au lieu de `0.269` (zinc-800) — corrigé pour surface zinc-800
- Build réussi (Next.js 16.2.2 Turbopack) : 0 erreur TypeScript, warning Turbopack workspace-root pre-existing non-bloquant

### Completion Notes List

- `globals.css` : token `--color-agent-edge-active: #60a5fa` (blue-400) ajouté dans `@theme inline`
- `globals.css` : palette `.dark` alignée zinc — background zinc-950, card/surface zinc-800, border zinc-700, foreground zinc-100, muted-foreground zinc-400
- `page.tsx` : layout 2 colonnes créé — topbar h-14 + zone diagramme flex-1 + sidebar w-80 `hidden lg:flex` + footer LaunchBar h-20
- `layout.tsx` : non modifié (déjà correct)
- Build `npm run build` : ✅ 0 erreur TypeScript, compilation Turbopack réussie
- Contraste WCAG AA : zinc-100 (#f4f4f5) sur zinc-950 (#09090b) = ratio ~19:1 ✅

### File List

- frontend/src/app/globals.css (modifié — token edge-active ajouté + palette .dark alignée zinc)
- frontend/src/app/page.tsx (modifié — layout 2 colonnes remplaçant la page Next.js par défaut)

### Change Log

- 2026-04-04 : Implémentation Story 1.2 — tokens agent + palette dark zinc + layout 2 colonnes
