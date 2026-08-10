COMPOSE = docker compose
PORT ?= 8100

.PHONY: help preflight build up down restart ps logs test test-php test-python \
	test-integration test-integration-down \
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
	@echo "  make test-integration       PostgreSQL + real-worker E2E suite (compose profile test)"
	@echo "  make test-integration-down  stop the test-profile containers"
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

# Integration + true E2E: ephemeral PostgreSQL (pg-test, tmpfs) and the
# REAL Python worker (ai-worker-test), both loopback-only via the compose
# "test" profile. The suite hard-refuses any database not ending in
# `_test` and uses a disposable data root under /srv/data/mnemosyne/tmp.
test-integration:
	mkdir -p /srv/data/mnemosyne/tmp/e2e-data
	$(COMPOSE) --profile test build ai-worker-test
	$(COMPOSE) --profile test up -d --wait pg-test ai-worker-test
	cd apps/web && \
		RUN_INTEGRATION=1 \
		DB_CONNECTION=pgsql \
		DB_HOST=127.0.0.1 \
		DB_PORT=8109 \
		DB_DATABASE=mnemosyne_test \
		DB_USERNAME=mnemosyne_test \
		DB_PASSWORD=mnemosyne_test_only \
		MNEMOSYNE_TEST_DATA_ROOT=/srv/data/mnemosyne/tmp/e2e-data \
		MNEMOSYNE_TEST_WORKER_PORT=8108 \
		php artisan test --testsuite=Integration

test-integration-down:
	$(COMPOSE) --profile test stop pg-test ai-worker-test
	$(COMPOSE) --profile test rm -f pg-test ai-worker-test

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
# Always as the mnemosyne user: root-created files under /data would be
# unreadable by the worker (uid 1003).
artisan:
	$(COMPOSE) exec --user mnemosyne app php artisan $(CMD)

migrate:
	$(COMPOSE) exec --user mnemosyne app php artisan migrate --force
