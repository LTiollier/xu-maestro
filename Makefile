.PHONY: serve-backend serve-frontend serve docker-up docker-down docker-build

serve-backend:
	cd backend && PHP_CLI_SERVER_WORKERS=4 php artisan serve --no-reload

serve-frontend:
	cd frontend && npm run dev

docker-build:
	docker compose build

docker-up:
	docker compose up

docker-down:
	docker compose down