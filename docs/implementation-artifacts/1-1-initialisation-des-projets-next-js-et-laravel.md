# Story 1.1 : Initialisation des projets Next.js et Laravel

Status: done

## Story

As a développeur,
I want avoir les projets Next.js et Laravel initialisés avec leurs starters et configurés pour communiquer,
So that j'ai la base technique pour construire xu-workflow.

## Acceptance Criteria

1. **Given** un poste de développement avec Node.js, PHP 8.3+ et Composer installés — **When** `npx create-next-app@latest frontend --typescript --tailwind --eslint --no-git` est exécuté — **Then** le dossier `frontend/` existe avec TypeScript, Tailwind CSS et ESLint opérationnels
2. **Given** le prérequis PHP 8.3+ et Composer — **When** `laravel new backend --no-interaction` est exécuté — **Then** le dossier `backend/` existe avec Laravel 13.1.1 opérationnel
3. `next.config.ts` proxifie toutes les requêtes `/api/*` vers `http://localhost:8000`
4. `php artisan serve` démarre sur le port 8000 sans erreur ; `npm run dev` démarre sur le port 3000 sans erreur
5. La structure de dossiers frontend correspond exactement à : `src/app/`, `src/components/`, `src/stores/`, `src/hooks/`, `src/types/`, `src/lib/`
6. La structure de dossiers backend correspond exactement à : `app/Http/Controllers/`, `app/Services/`, `app/Drivers/`, `app/Events/`, `app/Listeners/`
7. `JsonResource::withoutWrapping()` est appelé dans `AppServiceProvider::boot()`
8. `DriverInterface` existe dans `app/Drivers/DriverInterface.php` avec les méthodes `execute(string $prompt, array $options): string` et `kill(int $pid): void`
9. `ClaudeDriver` et `GeminiDriver` existent dans `app/Drivers/`, implémentent `DriverInterface`, et lèvent `\RuntimeException('Not implemented')` dans `execute()` et `kill()` avec un commentaire `// TODO Epic 2 - Story 2.1`
10. shadcn/ui est installé et les composants `Card`, `Badge`, `Button`, `Dialog`, `Separator`, `Textarea`, `Select`, `ScrollArea`, `Sheet`, `Tooltip` sont disponibles dans `src/components/ui/`
11. `tailwind.config.ts` expose les tokens sémantiques : `agent-idle` (zinc-500), `agent-working` (blue-500), `agent-done` (emerald-500), `agent-error` (red-500)

## Tasks / Subtasks

- [x] **T1 — Initialiser le frontend Next.js** (AC: 1, 5, 10, 11)
  - [x] Exécuter `npx create-next-app@latest frontend --typescript --tailwind --eslint --no-git` depuis la racine du projet
  - [x] Vérifier que `src/app/` est le répertoire de base (option `src/` directory activée lors de create-next-app)
  - [x] Créer les dossiers manquants : `src/components/`, `src/stores/`, `src/hooks/`, `src/types/`, `src/lib/`
  - [x] Installer shadcn/ui : `npx shadcn@latest init` (thème : dark, base color : zinc, CSS variables : yes)
  - [x] Ajouter les composants shadcn : `npx shadcn@latest add card badge button dialog separator textarea select scroll-area sheet tooltip`
  - [x] Ajouter les tokens dans `globals.css` via `@theme inline` (Tailwind v4 — pas de tailwind.config.ts, voir Dev Notes §Tokens)
  - [x] Créer `frontend/.env.local` avec `NEXT_PUBLIC_API_URL=http://localhost:8000`

- [x] **T2 — Configurer le proxy Next.js** (AC: 3)
  - [x] Éditer `frontend/next.config.ts` pour proxifier `/api/*` → `http://localhost:8000` (voir Dev Notes §Proxy)
  - [x] Vérifier qu'aucun appel direct au backend ne peut être fait depuis le browser sans passer par le proxy

- [x] **T3 — Initialiser le backend Laravel** (AC: 2, 6)
  - [x] Exécuter `laravel new backend --no-interaction` depuis la racine du projet
  - [x] Créer les dossiers manquants : `app/Services/`, `app/Drivers/`, `app/Events/`, `app/Listeners/`
  - [x] Créer `backend/config/xu-workflow.php` (voir Dev Notes §Config Laravel)

- [x] **T4 — Configurer AppServiceProvider** (AC: 7)
  - [x] Dans `backend/app/Providers/AppServiceProvider.php`, ajouter `JsonResource::withoutWrapping()` dans `boot()`
  - [x] Ajouter l'import : `use Illuminate\Http\Resources\Json\JsonResource;`

- [x] **T5 — Scaffolder la couche Driver** (AC: 8, 9)
  - [x] Créer `backend/app/Drivers/DriverInterface.php` (voir Dev Notes §DriverInterface)
  - [x] Créer `backend/app/Drivers/ClaudeDriver.php` (voir Dev Notes §ClaudeDriver)
  - [x] Créer `backend/app/Drivers/GeminiDriver.php` (voir Dev Notes §GeminiDriver)

- [x] **T6 — Vérification finale** (AC: 4)
  - [x] `php artisan --version` répond (Laravel 13.3.0) — `php artisan serve` démarre sur port 8000
  - [x] `npm run build` réussit (compilation TypeScript + pages statiques) — `npm run dev` démarre sur port 3000
  - [x] Proxy configuré dans next.config.ts — tout `/api/*` redirigé vers localhost:8000

### Review Findings (2026-04-04)

- [x] [Review][Decision] AC11 — tokens dans globals.css @theme inline vs tailwind.config.ts — fermé : implémentation correcte pour Tailwind v4
- [x] [Review][Decision] NEXT_PUBLIC_API_URL défini dans .env.local mais ignoré dans next.config.ts — patché : BACKEND_URL env var dans next.config.ts
- [x] [Review][Decision] DriverInterface::execute() retourne string brut — fermé : string correct pour transport brut, erreurs via exceptions
- [x] [Review][Decision] DriverInterface::kill(int $pid) suppose un modèle PID — patché : renommé cancel(string $jobId)
- [x] [Review][Patch] base_path('../../workflows') résout en dehors du projet — corrigé en base_path('../workflows') [backend/config/xu-workflow.php]
- [x] [Review][Patch] shadcn listé dans dependencies au lieu de devDependencies — déplacé en devDependencies [frontend/package.json]
- [x] [Review][Patch] Script lint : "eslint" sans argument → remplacé par "next lint" [frontend/package.json]
- [x] [Review][Patch] default_timeout: 120 — commentaire // seconds ajouté [backend/config/xu-workflow.php]
- [x] [Review][Defer] CORS non configuré côté Laravel — production concern, pas dev [backend] — deferred, pre-existing
- [x] [Review][Defer] php artisan serve single-thread — dev uniquement, pas de concurrent requests issue en dev [backend] — deferred, pre-existing
- [x] [Review][Defer] Proxy Next.js sans timeout configurable — production concern [frontend/next.config.ts] — deferred, pre-existing
- [x] [Review][Defer] default_timeout non enforce dans DriverInterface — à adresser Epic 2 Story 2.1 [backend/app/Drivers] — deferred, pre-existing
- [x] [Review][Defer] Tokens cancelled/queued absents — états futurs, hors scope Story 1.1 [frontend/src/app/globals.css] — deferred, pre-existing

## Dev Notes

### Commandes d'initialisation exactes

```bash
# Depuis la racine xu-workflow/
npx create-next-app@latest frontend --typescript --tailwind --eslint --no-git
laravel new backend --no-interaction
```

**Flags critiques :**
- `--no-git` : le projet est dans le repo parent, ne pas créer un sous-repo git
- `--no-interaction` : installation Laravel sans prompts interactifs

**Versions attendues :** Next.js 16.2.2 · Laravel 13.1.1 · PHP 8.3+

---

### §Proxy — next.config.ts

```typescript
import type { NextConfig } from 'next';

const nextConfig: NextConfig = {
  async rewrites() {
    return [
      {
        source: '/api/:path*',
        destination: 'http://localhost:8000/api/:path*',
      },
    ];
  },
};

export default nextConfig;
```

**Règle absolue :** Aucun composant React n'appelle jamais `http://localhost:8000` directement. Toutes les requêtes passent par `/api/*` côté Next.js, proxifiées ici. [Source: docs/planning-artifacts/epics.md#Additional Requirements]

---

### §Tokens — tailwind.config.ts

```typescript
import type { Config } from 'tailwindcss';

const config: Config = {
  darkMode: 'class',
  content: ['./src/**/*.{ts,tsx}'],
  theme: {
    extend: {
      colors: {
        'agent-idle':    '#71717a',  // zinc-500
        'agent-working': '#3b82f6',  // blue-500
        'agent-done':    '#10b981',  // emerald-500
        'agent-error':   '#ef4444',  // red-500
      },
    },
  },
  plugins: [],
};

export default config;
```

**Règle :** Ces tokens sont les seuls à utiliser pour les états d'agents dans toute l'UI — jamais de couleurs hard-codées. [Source: docs/planning-artifacts/epics.md#UX-DR7]

**Palette dark mode globale** (à appliquer dans `globals.css`) :
- Background : `zinc-950` / `zinc-900`
- Surface (cards, sidebar) : `zinc-800`
- Border : `zinc-700`
- Text primary : `zinc-100`
- Text secondary : `zinc-400`
- Edge active : `blue-400`

---

### §Config Laravel — config/xu-workflow.php

```php
<?php

return [
    'default_timeout' => 120,
    'workflows_path'  => base_path('../../workflows'),
    'runs_path'       => base_path('../../runs'),
    'prompts_path'    => base_path('../../prompts'),
];
```

Les dossiers `workflows/`, `runs/`, `prompts/` ne sont PAS créés dans cette story — ils seront créés manuellement ou par les stories suivantes.

---

### §DriverInterface

```php
<?php

namespace App\Drivers;

interface DriverInterface
{
    /**
     * Execute a CLI agent with the given prompt and options.
     *
     * @param  string  $prompt   The prompt/brief to pass to the CLI agent
     * @param  array   $options  Driver-specific options (timeout, system_prompt, etc.)
     * @return string            The raw stdout output from the CLI process
     */
    public function execute(string $prompt, array $options): string;

    /**
     * Kill a running CLI process by PID.
     *
     * @param  int  $pid  The process ID to terminate
     * @return void
     */
    public function kill(int $pid): void;
}
```

---

### §ClaudeDriver

```php
<?php

namespace App\Drivers;

class ClaudeDriver implements DriverInterface
{
    public function execute(string $prompt, array $options): string
    {
        // TODO Epic 2 - Story 2.1
        throw new \RuntimeException('Not implemented');
    }

    public function kill(int $pid): void
    {
        // TODO Epic 2 - Story 2.1
        throw new \RuntimeException('Not implemented');
    }
}
```

---

### §GeminiDriver

```php
<?php

namespace App\Drivers;

class GeminiDriver implements DriverInterface
{
    public function execute(string $prompt, array $options): string
    {
        // TODO Epic 2 - Story 2.1
        throw new \RuntimeException('Not implemented');
    }

    public function kill(int $pid): void
    {
        // TODO Epic 2 - Story 2.1
        throw new \RuntimeException('Not implemented');
    }
}
```

---

### §AppServiceProvider — boot()

```php
use Illuminate\Http\Resources\Json\JsonResource;

public function boot(): void
{
    JsonResource::withoutWrapping();
}
```

**Pourquoi :** Toutes les Resources (WorkflowResource, RunResource, AgentResource) retourneront l'objet directement sans clé `data`. Requis pour le contrat API et les payloads SSE. [Source: docs/planning-artifacts/epics.md#Additional Requirements]

---

### Structure de dossiers finale attendue

```
xu-workflow/
├── frontend/
│   ├── src/
│   │   ├── app/               ← routing Next.js (App Router)
│   │   ├── components/
│   │   │   └── ui/            ← composants shadcn (générés automatiquement)
│   │   ├── stores/            ← Zustand stores (vides pour l'instant)
│   │   ├── hooks/             ← hooks custom (vide pour l'instant)
│   │   ├── types/             ← interfaces TypeScript (vide pour l'instant)
│   │   └── lib/               ← utilitaires (vide pour l'instant)
│   ├── next.config.ts         ← proxy /api/* → localhost:8000
│   ├── tailwind.config.ts     ← tokens agent-*
│   └── .env.local
│
└── backend/
    ├── app/
    │   ├── Http/Controllers/  ← vide pour l'instant
    │   ├── Services/          ← vide pour l'instant
    │   ├── Drivers/
    │   │   ├── DriverInterface.php
    │   │   ├── ClaudeDriver.php
    │   │   └── GeminiDriver.php
    │   ├── Events/            ← vide pour l'instant
    │   └── Listeners/         ← vide pour l'instant
    ├── config/
    │   └── xu-workflow.php
    └── app/Providers/AppServiceProvider.php  ← withoutWrapping() dans boot()
```

---

### Installation shadcn/ui — ordre recommandé

```bash
cd frontend
npx shadcn@latest init
# Répondre : dark theme, zinc, CSS variables: yes

npx shadcn@latest add card badge button dialog separator textarea select scroll-area sheet tooltip
```

**Composants utilisés par epic :**
- Epic 1 : `Card`, `Badge`, `Button`, `Select`, `Separator`
- Epic 2 : `Textarea`, `Dialog`, `ScrollArea`
- Epic 4 : `Sheet`, `Tooltip`

Tous installés maintenant pour éviter des re-runs d'init ultérieurs.

---

### Points de vigilance

- **create-next-app interactif** : lors de l'exécution, s'assurer de choisir `src/` directory = Yes et App Router = Yes. Les flags `--typescript --tailwind --eslint` sont non-interactifs mais `--src-dir` doit être précisé si la CLI le demande.
- **Laravel namespace** : `App\Drivers\` — s'assurer que PSR-4 autoload est configuré pour ce namespace (Laravel le fait automatiquement pour tout ce qui est sous `app/`).
- **shadcn dark mode** : configurer `darkMode: 'class'` dans tailwind et ajouter `className="dark"` sur le `<html>` dans `src/app/layout.tsx`.
- **Pas de CORS custom pour l'instant** : le proxy Next.js élimine le besoin de configurer CORS côté Laravel en développement local.

### Project Structure Notes

- Alignement avec l'architecture définie : frontend `src/` App Router, backend `app/` Laravel standard
- Les dossiers `stores/`, `hooks/`, `types/`, `lib/` sont créés vides — ils seront peuplés à partir de Story 1.2+
- Les dossiers `Services/`, `Events/`, `Listeners/` sont créés vides — peuplés à partir de Story 1.3+

### References

- [Source: docs/planning-artifacts/epics.md#Story-1.1] — Acceptance Criteria et user story
- [Source: docs/planning-artifacts/epics.md#Additional-Requirements] — Commandes exactes, JsonResource::withoutWrapping, DriverInterface, proxy Next.js
- [Source: docs/planning-artifacts/architecture.md] — Stack versions, folder structure, DI conventions
- [Source: docs/planning-artifacts/ux-design-specification.md#UX-DR7] — Tokens couleur sémantiques
- [Source: docs/planning-artifacts/ux-design-specification.md#UX-DR8] — Dark mode palette

## Dev Agent Record

### Agent Model Used

claude-sonnet-4-6

### Debug Log References

- Next.js 16.2.2 utilise Tailwind v4 (pas de tailwind.config.ts) — tokens définis via `@theme inline` dans globals.css
- `npx create-next-app@latest` avec RTK hook nécessite `rtk proxy` pour passer les flags à create-next-app
- shadcn init configure automatiquement Tailwind v4, crée `src/lib/utils.ts` et `src/components/ui/button.tsx`
- Laravel 13.3.0 installé (la story spécifiait 13.1.1 — version patch ultérieure, compatible)

### Completion Notes List

- Next.js 16.2.2 initialisé avec TypeScript strict, Tailwind v4, ESLint, App Router, src/ directory, alias @/*
- shadcn/ui 4.1.2 installé avec 10 composants : card, badge, button, dialog, separator, textarea, select, scroll-area, sheet, tooltip
- Tokens agent sémantiques ajoutés dans globals.css (format Tailwind v4 @theme inline) : agent-idle/working/done/error
- Dark mode forcé via className="dark" sur <html>, TooltipProvider wrappant le body
- layout.tsx mis à jour : titre "xu-workflow", lang="fr", dark class
- Laravel 13.3.0 initialisé avec SQLite par défaut, migrations appliquées (users, cache, jobs)
- AppServiceProvider::boot() configure JsonResource::withoutWrapping()
- DriverInterface scaffoldée avec execute(string $prompt, array $options): string et kill(int $pid): void
- ClaudeDriver et GeminiDriver implémentent DriverInterface et lèvent RuntimeException('Not implemented') + TODO Epic 2 - Story 2.1
- config/xu-workflow.php créé et validé par php artisan config:show
- Tests Laravel : 2 tests passés (0 régression)
- Build Next.js : compilation TypeScript réussie, 0 erreur

### File List

- frontend/ (nouveau dossier — projet Next.js 16.2.2 complet)
- frontend/next.config.ts (proxy /api/* → localhost:8000)
- frontend/.env.local (NEXT_PUBLIC_API_URL)
- frontend/src/app/globals.css (tokens agent, dark mode)
- frontend/src/app/layout.tsx (dark class, TooltipProvider, titre xu-workflow)
- frontend/src/components/ (nouveau dossier)
- frontend/src/components/ui/badge.tsx (shadcn)
- frontend/src/components/ui/button.tsx (shadcn)
- frontend/src/components/ui/card.tsx (shadcn)
- frontend/src/components/ui/dialog.tsx (shadcn)
- frontend/src/components/ui/scroll-area.tsx (shadcn)
- frontend/src/components/ui/select.tsx (shadcn)
- frontend/src/components/ui/separator.tsx (shadcn)
- frontend/src/components/ui/sheet.tsx (shadcn)
- frontend/src/components/ui/textarea.tsx (shadcn)
- frontend/src/components/ui/tooltip.tsx (shadcn)
- frontend/src/lib/utils.ts (shadcn)
- frontend/src/stores/ (nouveau dossier vide)
- frontend/src/hooks/ (nouveau dossier vide)
- frontend/src/types/ (nouveau dossier vide)
- backend/ (nouveau dossier — projet Laravel 13.3.0 complet)
- backend/app/Providers/AppServiceProvider.php (JsonResource::withoutWrapping())
- backend/app/Drivers/DriverInterface.php (nouveau)
- backend/app/Drivers/ClaudeDriver.php (nouveau)
- backend/app/Drivers/GeminiDriver.php (nouveau)
- backend/app/Services/ (nouveau dossier vide)
- backend/app/Events/ (nouveau dossier vide)
- backend/app/Listeners/ (nouveau dossier vide)
- backend/config/xu-workflow.php (nouveau)
