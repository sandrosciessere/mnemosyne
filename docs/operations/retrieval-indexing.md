# Retrieval indexing — operations

All commands run via `make artisan CMD="…"` (as the mnemosyne user).

## Models (local, CPU-only — book text never leaves the server)

| model_key | HF id @ pinned revision | dims | license |
|---|---|---|---|
| e5-small-v1 | intfloat/multilingual-e5-small @ 614241f6… | 384 | MIT |
| mmarco-mini-v1 | cross-encoder/mmarco-mMiniLMv2-L12-H384-v1 @ 1427fd65… | — | Apache-2.0 |

Cache: `/srv/data/mnemosyne/models/hf` (`HF_HOME`, mounted at
`/data/models/hf` in the worker; never in Git). Provision/refresh:
`docker compose exec ai-worker python -m app.retrieval.provision --all`.
The worker is strictly offline unless `WORKER_ALLOW_MODEL_DOWNLOAD=1`;
unprovisioned models yield 503 `MODEL_NOT_PROVISIONED` (retryable).
Registry + cache state: `GET /internal/v1/retrieval/models` (token-auth).

## Lifecycle

```
mnemosyne:retrieval:create-generation      # snapshot config → building
mnemosyne:retrieval:index --all-ready --generation=<ulid> [--sync]
mnemosyne:retrieval:activate <ulid> [--allow-empty]
mnemosyne:retrieval:status
mnemosyne:retrieval:evaluate               # synthetic benchmark (see testing doc)
```

- `index --all-ready` backfills every retrieval-eligible asset missing or
  not-ready in the generation; re-running touches only unfinished work
  (idempotent, resume-safe — a crash mid-embedding resumes at the next
  missing chunk). Iteration is keyset-paginated (`lazyById`, page size
  `MNEMOSYNE_RETRIEVAL_BACKFILL_BATCH`, default 500) — bounded memory at
  any library size, no offset-pagination row skips. `--asset=<ulid>`
  targets one book; without `--sync` jobs go to the `retrieval` queue
  (Horizon `supervisor-retrieval`, concurrency
  `MNEMOSYNE_RETRIEVAL_CONCURRENCY`, default 2).
- Newly ingested books auto-enqueue into the ACTIVE generation when they
  reach ready_for_enrichment (idempotent hook; no active generation =
  no-op).
- Activation refuses an empty generation without `--allow-empty`, flips
  the previous active one to `superseded` and PRESERVES its data
  (blue/green). Rebuild = create new generation → index → activate.
  Superseded-data cleanup is intentionally NOT automated in M2.
- Failures: `retrieval:status` lists failed states with error codes.
  `WORKER_UNAVAILABLE`/`MODEL_NOT_PROVISIONED` retry automatically with
  backoff (max 5 attempts) — provision models, then `index` again.
  `SOURCE_HASH_MISMATCH`/`SOURCE_ARTIFACTS_MISSING` are permanent until
  the source is repaired (re-ingest), then `index` again.

## CPU considerations

Embedding ~150 texts/s (batch 16, 4-CPU container); indexing the 4-book
real corpus (1463 chunks) took ≈3m48s end-to-end including model load.
Reranking 24 candidates ≈3.3–3.6 s warm — the dominant search cost;
first request after worker restart adds ~8 s model load. Keep
`supervisor-retrieval` concurrency low (shared host); bulk backfills are
CPU-bound in the worker.

## Admin UI

`/admin/retrieval` — generations, model identities, per-state counts,
failures. `/admin/retrieval/debugger` — mode-by-mode search with score
provenance and EvidenceSpan inspection (admin-only; `debug` flag).
