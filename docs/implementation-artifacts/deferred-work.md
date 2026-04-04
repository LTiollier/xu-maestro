# Deferred Work

## Deferred from: code review of 1-1-initialisation-des-projets-next-js-et-laravel (2026-04-04)

- **CORS non configuré** — Laravel `config/cors.php` non modifié. Pas nécessaire en dev local (proxy Next.js gère la séparation), mais à configurer pour tout déploiement non-localhost.
- **php artisan serve single-thread** — Le serveur de développement Laravel ne gère pas les requêtes concurrentes. Non-bloquant pour le dev local, mais à considérer si tests concurrents ou Octane pour prod.
- **Proxy Next.js sans timeout configurable** — Les rewrites Next.js n'exposent pas de timeout configurable. Tout run bloqué côté backend bloquera le fetch frontend jusqu'au timeout browser. À adresser via des timeouts serveur-side dans Laravel pour Epic 2.
- **default_timeout non enforcé dans DriverInterface** — La config `xu-workflow.default_timeout` existe mais l'interface `DriverInterface::execute()` n'a pas de paramètre timeout explicite. Chaque driver devra lire la config manuellement. À formaliser lors de l'implémentation Story 2.1.
- **Tokens d'état agent manquants (cancelled, queued, retrying)** — Seuls idle/working/done/error définis. Les états futurs nécessiteront un ajout dans `globals.css @theme inline`. À faire lors des stories qui introduisent ces états.

## Deferred from: code review of 1-2-design-system-tokens-couleur-dark-mode-et-layout-principal (2026-04-04)

- **`h-full` vs `flex-1` sur le wrapper page.tsx** — `flex-1` est plus correct sémantiquement pour un enfant flex de body. `h-full` fonctionne en pratique. Refactorer lors du prochain rework de page.tsx.
- **`--border`/`--input` opaque vs transparent** — Passés de `oklch(1 0 0 / 10%)` à `oklch(0.32 0 0)`. Peut légèrement affecter les composants shadcn utilisant des opacités composited (`/30` etc.). Surveiller lors de Story 1.3+ quand les formulaires/inputs arrivent.
- **Tokens agent sans variantes dark mode** — `--color-agent-*` sont globaux (pas de dark override). Intentionnel (couleurs d'état sémantiques). Vérifier le contraste sur fond zinc-800 lors de Story 1.5 (AgentCard).
- **Sidebar masquée < 1024px sans affordance d'accès** — `hidden lg:flex` masque complètement la sidebar en mobile/tablette. À adresser en Story 2.7b avec un bouton toggle ou drawer.

## Deferred from: code review of 1-3-api-laravel-chargement-validation-et-exposition-des-workflows-yaml (2026-04-04)

- **Pas d'authentification sur GET /api/workflows** — Intentionnel, usage localhost single-user. Si le projet évolue vers un déploiement réseau, ajouter middleware auth sur les routes API.
- **AC 4 — 422/YAML_INVALID non implémenté** — La spec dit "si demandé explicitement" (optionnel). Aucun endpoint de validation dédié dans cette story. À implémenter si un besoin de validation explicite émerge (ex: endpoint `POST /api/workflows/validate`).
- **Extension `.yml` non scannée** — `glob('*.yaml')` ignore les fichiers `.yml`. Choix délibéré (spec utilise `.yaml` partout). À étendre si des utilisateurs apportent des fichiers `.yml`.
- **`base_path('../workflows')` fragile hors structure repo** — Résout correctement dans la structure actuelle. En cas de containerisation ou déploiement différent, ce chemin relatif devrait être remplacé par une variable d'environnement absolue.
- **`JsonResource::withoutWrapping()` global dans AppServiceProvider** — Intentionnel : toutes les Resources du projet ne doivent pas avoir le wrapper `data`. Si une future Resource a besoin du wrapper, utiliser `protected static $wrap = 'data'` localement.
