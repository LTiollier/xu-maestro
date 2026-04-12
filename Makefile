.PHONY: serve-backend serve-frontend serve

serve-backend:
	cd backend && PHP_CLI_SERVER_WORKERS=4 php artisan serve --no-reload

serve-frontend:
	cd frontend && npm run dev