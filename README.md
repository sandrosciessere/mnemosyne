# Mnemosyne

AI-powered library analysis, retrieval and research platform for a large
private EPUB collection (target scale: hundreds of thousands of books).

**Project status: library domain + EPUB ingestion.** Authentication,
roles, health endpoints and the containerized stack are in place, plus:
the bibliographic domain (Work → Edition → BookAsset), the submission /
approval workflow, a versioned five-stage ingestion pipeline
(hash → validate → parse → normalize → structure, ending in
READY_FOR_ENRICHMENT), safe EPUB 2/3 parsing in the Python worker,
exact + content deduplication, the admin processing control plane and
the `/api/v1` submission/admin API (`docs/api/openapi.yaml`,
`docs/architecture/epub-ingestion.md`). Milestone 2 added the retrieval
foundation: deterministic chunking with EvidenceSpan provenance, exact +
lexical + dense hybrid search with weighted RRF, local CPU embeddings and
versioned blue/green retrieval generations
(`docs/architecture/retrieval.md`). Milestone 3 added grounded question
answering: query intent classification, bounded evidence packets,
structured claim generation with independent per-claim verification,
epistemic labels, exact citations and the Evidence Reader
(`docs/architecture/grounded-answers.md`). Enrichment (M4), deep analysis
(M5) and the remaining MVP surface are tracked in
`docs/requirements/mvp-v1.1-traceability.md`.

- Staging URL: https://mnemosyne.shellrent.com
- License: not yet selected.

## Stack

| Component | Technology |
|---|---|
| Backend / API | Laravel 12 (PHP 8.4), Inertia, Sanctum-ready `/api/v1` |
| Frontend | React 19 + TypeScript + Tailwind CSS 4 (Vite) |
| Database | PostgreSQL 17 + pgvector (dedicated container) |
| Cache / queues / sessions | Redis 7 (dedicated container), Laravel Horizon |
| AI / document worker | Python 3.12 + FastAPI (skeleton) |
| Runtime | Docker Compose (project `mnemosyne`), host nginx reverse proxy |

## Repository layout

```
apps/web/            Laravel application (Inertia + React + TS)
services/ai-worker/  Python AI/document worker (skeleton)
services/mcp/        Future MCP adapter (documentation only)
docker/              Image definitions (php-fpm, nginx)
docs/                Architecture, ADRs, operations
compose.yaml         Stack definition
Makefile             Ergonomic commands
```

## Requirements

- Docker ≥ 29 with Compose ≥ v5
- For host-side tests/lint: PHP 8.4 + Composer, Node 20+

## Bootstrap

```bash
cp .env.example .env          # fill APP_KEY, DB_PASSWORD, REDIS_PASSWORD
chmod 600 .env
make build
make up
make migrate                  # migrations are always explicit
make health                   # /health/live, /health/ready, /api/v1/health
```

The first administrator is created interactively (no default passwords):

```bash
docker compose exec app php artisan mnemosyne:user:create-admin
```

Public self-registration is disabled by design.

## Start / stop / test

```bash
make up / make down / make ps / make logs
make test              # PHP suite (host, sqlite in-memory) + Python suite
make lint              # pint, eslint + prettier, ruff (check-only)
make test-integration  # PostgreSQL + real-worker E2E (compose profile "test")
```

## Data directory

Persistent data lives **outside the repository** in `/srv/data/mnemosyne`
(owner `mnemosyne:mnemosyne`), bind-mounted at `/data` in the containers:
`library/{incoming,original,extracted,exports}`, `models/`, `cache/`,
`tmp/`, `backups/tmp`. Original EPUBs are logically immutable. EPUB files,
model weights and credentials must never enter Git.

## Documentation

- `AGENTS.md` — mandatory guide for coding agents
- `docs/architecture/overview.md` — architecture and future direction
- `docs/architecture/ai-provider-boundaries.md` — AI provider abstraction
- `docs/adr/` — architecture decision records
- `docs/operations/` — environment, runbook, storage
