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

## Development session rules

- Normal coding sessions run **as the Unix user `mnemosyne`**
  (`sudo -iu mnemosyne` is executed by the administrator beforehand).
  **`whoami` is the first check** — if it is not `mnemosyne`, stop.
- **Never use `sudo` during normal development.** If an operation
  genuinely needs root (host nginx/Certbot, firewall, host systemd,
  Debian packages, Docker daemon config, ownership outside the Mnemosyne
  areas), stop and give the administrator the exact command instead.
- Run **`make preflight`** at the start of every significant session; it
  must end with `READY FOR DEVELOPMENT`.
- Check `git status` before modifying anything; investigate an unexpected
  dirty tree instead of building on top of it.
- Significant domain work happens on **feature branches**
  (`feat/…`, `fix/…`, `refactor/…`, `chore/…`) — never directly on
  `main`. `main` stays buildable, deployable and tested; merges are
  fast-forward with green tests/lint/build.
- **No force push. No destructive resets** (`git reset --hard` only in a
  true emergency, explicitly justified). No secrets in the repo, ever.
- Never touch other projects' services; tests must pass before any push.

## Library & ingestion invariants (do not violate)

The library domain and EPUB pipeline exist (see
`docs/architecture/epub-ingestion.md`, ADR-007/008/009). Any future work
must respect:

- `Work` ≠ `Edition` ≠ `BookAsset` — never collapse them; assets may be
  edition-less only while ingestion is in flight.
- Laravel owns ALL domain state. The Python worker never writes domain
  tables and never decides state transitions; it only transforms content
  behind `/internal/v1` (token-authenticated, data-root-relative paths).
- Original EPUBs are immutable and content-addressed
  (`library/original/sha256/aa/bb/{sha}.epub`); nothing ever edits or
  deletes them. Exact dedup is by file SHA-256 — never store a second
  copy of the same bytes.
- A content-fingerprint match (`content_sha256`) creates a duplicate
  CANDIDATE for admins — never an automatic destructive merge, and
  **never Edition identity by itself**: automatic Edition linking
  requires independent bibliographic corroboration (title + creator
  agreement) and is labeled, evidenced and reversible.
- A contributor's normalized name is a matching hint, never an identity
  key: homonyms must remain distinct rows (no unique constraint on
  normalized names).
- The identity hierarchy is strict — no layer may treat evidence as
  identity more aggressively than the layer beneath it allows.
  **BookAsset** identity = exact SHA-256 (physical duplicate) only.
  **Contributor** identity is never a normalized-name string.
  **Work** identity: a normalized title + an *unresolved* creator string
  is matching evidence, NEVER sufficient identity — do not auto-reuse a
  Work on that alone (it would re-introduce homonym collapse one level
  up); such siblings become a review candidate. Auto Work reuse requires a
  strong signal (an existing Edition reached via canonical identifier, or a
  corroborated content-fingerprint twin). **Edition** auto-adoption
  requires corroboration AND the absence of any conflicting strong metadata
  (ISBN/publisher+year/language). Prefer a duplicate provisional Work over
  a wrong automatic merge; false negatives are reconciled later, silent
  false-positive identity contaminates the knowledge base.
- Filesystem discovery is READ-ONLY over the source library and writes
  only its persistent manifest; creating submissions/copies is the
  separate, restart-safe import step.
- Every ingestion stage must stay idempotent and record its handler
  version in the stage attempt; artifacts are versioned per
  `pipeline_version` and written atomically (temp + rename).
- Source references (spine index, source href, fragment, ordinal,
  heading path, stable node id) must survive every future transformation
  — chunking/retrieval that drops them breaks citation-readiness.
- Hard security validation blocks (zip traversal, bombs, symlinks, XXE)
  are NEVER overrideable — not by admins, not by code. DRM is never
  bypassed.
- Status columns are strings + PHP enums (no PG enum types); public
  identifiers are ULIDs — never expose numeric ids in routes or APIs.
- Test fixtures: synthetic EPUBs only. No copyrighted EPUB may ever
  enter the repository.

## Shared server — safety rules (non-negotiable)

This is a shared host (`shde-ed837.serverlet.com`) running other projects
(duckmaze-support, shelly-ui-mcp, webforge). Never touch their
repositories, containers, databases, Docker networks/volumes, nginx vhosts
or `.env` files. Never run `docker system/volume/network/image prune`.
Never restart Docker, host nginx, host PostgreSQL (127.0.0.1:5433 — not
ours) or host Ollama. Only the host nginx exposes services publicly; the
Mnemosyne web ingress binds to loopback only (default `127.0.0.1:8100`).

## Ownership & secrets

- Repository files are owned by `mnemosyne:mnemosyne`; you normally *are*
  `mnemosyne`, so plain `git`/file commands are correct.
- Secrets live only in the untracked root `.env` (mode 0600). **Never
  commit** secrets, tokens, `.env`, EPUB files, model weights or database
  dumps. Check the staged diff before every commit.
- The GitHub remote is HTTPS with a stored fine-grained PAT (user
  `mnemosyne` only). Do not change the remote, do not print credentials.

## Day-to-day commands

```bash
make preflight                        # fast read-only session preflight
make build / up / down / ps / logs    # stack lifecycle (compose project: mnemosyne)
make migrate                          # explicit migrations (never automatic)
make test                             # PHP (host, sqlite) + Python (container)
make lint                             # CHECK-ONLY: pint --test, eslint, prettier --check, ruff check
make lint-ts-fix / make format-php    # explicit autofix commands
make health                           # smoke-check the three health endpoints
```

Migrations run **explicitly** (`make migrate`), never automatically at
container start. PHP tests run on the host checkout; the runtime image has
no dev dependencies. `make lint` never modifies files — before any push,
lint, tests and `npm run build` (in `apps/web`) must all be green.

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

## Agent start checklist

```text
Before coding:

1. whoami -> mnemosyne
2. make preflight
3. git status
4. read task + relevant ADR/docs
5. create/use feature branch
6. code
7. make lint
8. make test
9. review diff
10. commit/push
```
