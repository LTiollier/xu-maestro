.PHONY: serve-backend serve-frontend serve docker-up docker-down docker-build

build:
	docker compose build

up:
	docker compose up -d

down:
	docker compose down

reset:
	make down && make build && make up

clean:
	rm -rf /Users/leoelmy/Projects/test-workflow && mkdir /Users/leoelmy/Projects/test-workflow
