#!/bin/bash
set -e

cd /app

# Bootstrap .env
if [ ! -f .env ]; then
    cp .env.example .env
fi

# Generate APP_KEY if missing
if ! grep -qE "^APP_KEY=base64:" .env; then
    php artisan key:generate --force --no-interaction
fi

# Allow APP_KEY override via environment
if [ -n "$APP_KEY" ]; then
    sed -i "s|^APP_KEY=.*|APP_KEY=$APP_KEY|" .env
fi

# Ensure SQLite DB exists
mkdir -p database
touch database/database.sqlite

# Ensure storage/cache is writable
chmod -R 775 storage bootstrap/cache 2>/dev/null || true

# Run migrations
php artisan migrate --force --no-interaction

# Queue listener in background
php artisan queue:listen --tries=1 --timeout=0 &

echo "xu-workflow backend ready on :8000"

# Start Laravel dev server (PHP_CLI_SERVER_WORKERS set via env in docker-compose)
exec php artisan serve --host=0.0.0.0 --port=8000 --no-reload
