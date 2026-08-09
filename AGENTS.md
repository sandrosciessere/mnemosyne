# AGENTS.md — read this before changing anything

You are working on **Mnemosyne**, an AI-powered platform for analysis,
retrieval and research over a large private EPUB library. This file is the
contract every coding agent must follow. When in doubt, re-read it.

## What Mnemosyne is (and is not)

Mnemosyne is designed for a **global, centralized library on the order of
hundreds of thousands of EPUBs**, served to multiple users through ACLs.
It is **not** a toy `EPUB -> chunk -> embed -> chatbot` pipeline. Never
simplify the architecture toward naive top-k RAG:

- Retrieval will be **dynamic**: a pointed question needs few highly
  relevant passages; a longitudinal question ("how does the relationship
  between X and Y evolve across the book?") needs **coverage across the
  whole book**, not "top 5 chunks".
- The engine will combine exact, lexical, semantic and hybrid retrieval,
  reranking, query classification, multi-book and multilingual search.
- Answers must be **grounded and verifiable**: citations, counter-evidence
  search, a final verifier, and explicit labels
  (`FATTO TESTUALE`, `DEDUZIONE FORTE`, `INTERPRETAZIONE`,
  `EVIDENZA INSUFFICIENTE`). No arbitrary confidence percentages.
- The worst failure is a **wrong but convincing answer**; the second worst
  is missing an answer that exists in the text. Never build AI components
  that answer without sources.

## Architecture principles

- **Monorepo**: `apps/web` (Laravel + Inertia/React/TS), `services/ai-worker`
  (Python 3.12), `services/mcp` (future adapter), `docker/`, `docs/`.
- **REST API (`/api/v1/...`) is the canonical interface.** MCP is a future
  adapter over it and must never expose admin functions.
- **Ingestion will be a persistent, versioned state machine**
  (discover → hash → validate → parse → normalize → structure → enrich →
  chunk → embed → summarize → entities → relationships → index → verify →
  ready). Each state: persistent, idempotent, retryable, observable.
- **Bibliographic model**: `Work -> Edition -> BookAsset`; identical file
  hashes deduplicate assets.
- **Everything AI is behind a provider abstraction** (categories:
  embeddings, reranker, generation, verifier, deep-analysis — see
  `config/mnemosyne.php`). Never couple domain code to Ollama or any
  single provider. Embedding models/dimensions are **configurable and
  versioned**; never hardcode a single model or vector dimension into the
  schema. Index rebuilds will be blue/green (V1 serves while V2 builds).
- Parser, normalizer, chunking, prompts, summaries, extraction and
  retrieval profiles will all be **versioned**.

## Shared server — safety rules (non-negotiable)

This is a shared host (`shde-ed837.serverlet.com`) running other projects
(duckmaze-support, shelly-ui-mcp, webforge). Never touch their
repositories, containers, databases, Docker networks/volumes, nginx vhosts
or `.env` files. Never run `docker system/volume/network/image prune`.
Never restart Docker, host nginx, host PostgreSQL (127.0.0.1:5433 — not
ours) or host Ollama. Only the host nginx exposes services publicly; the
Mnemosyne web ingress binds to loopback only (default `127.0.0.1:8100`).

## Ownership & secrets

- All repository files are owned by `mnemosyne:mnemosyne`. Run Git and
  file operations as the `mnemosyne` Unix user (`sudo -u mnemosyne -H …`).
- Secrets live only in the untracked root `.env` (mode 0600). **Never
  commit** secrets, tokens, `.env`, EPUB files, model weights or database
  dumps. Check `git diff --cached` before every commit.
- The GitHub remote is HTTPS with a stored fine-grained PAT (user
  `mnemosyne` only). Do not change the remote, do not print credentials.

## Day-to-day commands

```bash
make build / up / down / ps / logs   # stack lifecycle (compose project: mnemosyne)
make migrate                          # explicit migrations (never automatic)
make test                             # PHP (host, sqlite) + Python (container)
make lint                             # pint, eslint/prettier, ruff
make health                           # smoke-check the three health endpoints
```

Migrations run **explicitly** (`make migrate`), never automatically at
container start. PHP tests run on the host checkout; the runtime image has
no dev dependencies. Frontend must always build (`npm run build`) before
`vendor/bin/pint --test`, `npm run lint`, and the test suite are green —
all of them are required before any push.

## Data

Persistent data lives in `/srv/data/mnemosyne` (bind-mounted at `/data`),
never in the repository. Original EPUBs are logically immutable. Future
bulk import must handle `Author/Title/file.epub` filesystem trees at the
scale of hundreds of thousands of files.

## Commits

Small coherent commits, imperative subject (`feat: …`, `chore: …`,
`docs: …`). Push only when tests and lint pass. Never force-push, never
rewrite published history. Do not add GitHub Actions workflows (the deploy
PAT intentionally has no Workflows permission).
