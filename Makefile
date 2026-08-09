COMPOSE = docker compose
PORT ?= 8100

.PHONY: build up down restart ps logs test test-php test-python lint lint-php lint-ts lint-python health shell worker-shell artisan migrate

build:
	$(COMPOSE) build

up:
	$(COMPOSE) up -d

down:
	$(COMPOSE) down

restart:
	$(COMPOSE) restart

ps:
	$(COMPOSE) ps

logs:
	$(COMPOSE) logs -f --tail=100

# PHP tests run on the host checkout (dev dependencies are not shipped in
# the runtime image); they use sqlite in-memory, no stack required.
test-php:
	cd apps/web && php artisan test

test-python:
	$(COMPOSE) run --rm --no-deps --user root ai-worker \
		sh -c "pip install -q -r requirements-dev.txt && pytest -q"

test: test-php test-python

lint-php:
	cd apps/web && ./vendor/bin/pint --test

lint-ts:
	cd apps/web && npm run lint && npm run format:check

lint-python:
	$(COMPOSE) run --rm --no-deps --user root ai-worker \
		sh -c "pip install -q -r requirements-dev.txt && ruff check ."

lint: lint-php lint-ts lint-python

health:
	@curl -fsS http://127.0.0.1:$(PORT)/health/live && echo
	@curl -fsS http://127.0.0.1:$(PORT)/health/ready && echo
	@curl -fsS http://127.0.0.1:$(PORT)/api/v1/health && echo

shell:
	$(COMPOSE) exec app bash

worker-shell:
	$(COMPOSE) exec ai-worker bash

# Usage: make artisan CMD="migrate --force"
artisan:
	$(COMPOSE) exec app php artisan $(CMD)

migrate:
	$(COMPOSE) exec app php artisan migrate --force
