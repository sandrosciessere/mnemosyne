# Architecture overview

## Current state (application bootstrap)

```
Internet ──▶ host nginx (80/443, TLS) ──▶ 127.0.0.1:8100
                                             │
                                   ┌─────────▼─────────┐
                                   │  web (nginx)      │  edge network
                                   └─────────┬─────────┘
                                             │ fastcgi
                                   ┌─────────▼─────────┐
                                   │  app (php-fpm)    │  edge + backend
                                   │  Laravel 12       │
                                   └───┬──────┬────────┘
                 backend network       │      │
        ┌──────────┬──────────┬────────┘      │
        ▼          ▼          ▼               ▼
   pg (17 +    redis 7    horizon +      ai-worker
   pgvector)              scheduler      (Python 3.12)
                                             │ optional, via host-gateway
                                             ▼
                                      host Ollama :11434
```

- Compose project name: `mnemosyne`. Only `web` publishes a port, loopback
  only (`127.0.0.1:8100`). PostgreSQL, Redis, php-fpm and the worker are
  never reachable from the host network.
- Sessions, cache and queues live in Redis; queue processing is Horizon
  (admin-gated dashboard at `/horizon`).
- Persistent data: named volumes for pg/redis, bind mount
  `/srv/data/mnemosyne -> /data` for library/models/cache.
- All processes that write to `/data` run as uid/gid 1003 (host user
  `mnemosyne`).

## Product principles (decided)

- Global centralized library; EPUB assets are deduplicated by hash and
  never duplicated per user; access is governed by ACLs.
- Roles for MVP: `admin`, `user` (enum, default `user`).
- Bibliographic model: `Work -> Edition -> BookAsset`.
- Analysis modes: Fast, Accurate, Deep Analysis (long-running, with
  planning, multiple retrievals, evidence and counter-evidence search,
  citation verification, claim labeling).
- Anti-hallucination is a first-class requirement: groundedness, recall,
  verifiable citations, explicit uncertainty. See AGENTS.md.

## Future ingestion state machine (not implemented)

discover → hash → validate → parse → normalize → structure → enrich →
chunk → embed → summarize → entities → relationships → index → verify →
ready.

Each state will be persistent, idempotent, retryable, versioned and
observable by admins. Parsers, normalizers, chunking strategies, embedding
models (and their dimensions), rerankers, prompts and retrieval profiles
are all versioned; embedding upgrades use blue/green indexes with an
atomic switch.

## Future retrieval engine (not implemented)

Exact + lexical + semantic + hybrid retrieval with reranking, query
classification, dynamic retrieval depth, coverage-aware retrieval for
longitudinal questions, multi-book and multilingual search, answers with
verifiable citations and a final verifier. The number of passages fed to
generation is never a fixed top-k.

## Quality benchmark (future)

A golden dataset of 10 books with hand-written hard questions will track:
retrieval recall, citation correctness, groundedness, false positives/
negatives, coverage, latency and cost.
