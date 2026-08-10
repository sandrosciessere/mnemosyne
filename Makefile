COMPOSE = docker compose
PORT ?= 8100

.PHONY: help preflight build up down restart ps logs test test-php test-python \
	lint lint-php lint-ts lint-python lint-ts-fix format-php health \
	shell worker-shell artisan migrate

help:
	@echo "Mnemosyne — main commands"
	@echo ""
	@echo "  make preflight     fast read-only development preflight"
	@echo "  make build         build stack images"
	@echo "  make up            start stack"
	@echo "  make down          stop stack"
	@echo "  make ps            stack status"
	@echo "  make logs          follow logs"
	@echo "  make test          PHP + Python test suites"
	@echo "  make lint          check-only lint (pint, eslint, prettier, ruff)"
	@echo "  make lint-ts-fix   autofix TS (eslint --fix + prettier --write)"
	@echo "  make format-php    autofix PHP style (pint)"
	@echo "  make health        smoke-check the health endpoints"
	@echo "  make migrate       run migrations (explicit, never automatic)"
	@echo "  make artisan CMD=… run artisan in the app container"
	@echo "  make shell         shell in the app container"
	@echo "  make worker-shell  shell in the ai-worker container"

preflight:
	@bash scripts/preflight.sh

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

# ---- lint = check-only; autofix commands are explicit and separate ----

lint-php:
	cd apps/web && ./vendor/bin/pint --test

lint-ts:
	cd apps/web && npm run lint && npm run format:check

lint-python:
	$(COMPOSE) run --rm --no-deps --user root ai-worker \
		sh -c "pip install -q -r requirements-dev.txt && ruff check ."

lint: lint-php lint-ts lint-python

lint-ts-fix:
	cd apps/web && npm run lint:fix && npm run format

format-php:
	cd apps/web && ./vendor/bin/pint

health:
	@curl -fsS http://127.0.0.1:$(PORT)/health/live && echo
	@curl -fsS http://127.0.0.1:$(PORT)/health/ready && echo
	@curl -fsS http://127.0.0.1:$(PORT)/api/v1/health && echo

shell:
	$(COMPOSE) exec app bash

worker-shell:
	$(COMPOSE) exec ai-worker bash

# Usage: make artisan CMD="route:list"
artisan:
	$(COMPOSE) exec app php artisan $(CMD)

migrate:
	$(COMPOSE) exec app php artisan migrate --force
