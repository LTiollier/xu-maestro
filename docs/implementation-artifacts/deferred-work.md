# Deferred Work

## Deferred from: code review of 1-1-initialisation-des-projets-next-js-et-laravel (2026-04-04)

- **CORS non configuré** — Laravel `config/cors.php` non modifié. Pas nécessaire en dev local (proxy Next.js gère la séparation), mais à configurer pour tout déploiement non-localhost.
- **php artisan serve single-thread** — Le serveur de développement Laravel ne gère pas les requêtes concurrentes. Non-bloquant pour le dev local, mais à considérer si tests concurrents ou Octane pour prod.
- **Proxy Next.js sans timeout configurable** — Les rewrites Next.js n'exposent pas de timeout configurable. Tout run bloqué côté backend bloquera le fetch frontend jusqu'au timeout browser. À adresser via des timeouts serveur-side dans Laravel pour Epic 2.
- **default_timeout non enforcé dans DriverInterface** — La config `xu-workflow.default_timeout` existe mais l'interface `DriverInterface::execute()` n'a pas de paramètre timeout explicite. Chaque driver devra lire la config manuellement. À formaliser lors de l'implémentation Story 2.1.
- **Tokens d'état agent manquants (cancelled, queued, retrying)** — Seuls idle/working/done/error définis. Les états futurs nécessiteront un ajout dans `globals.css @theme inline`. À faire lors des stories qui introduisent ces états.
