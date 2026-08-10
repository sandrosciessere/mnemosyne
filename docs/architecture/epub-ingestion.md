# EPUB ingestion — library domain and processing pipeline

Status: implemented by milestone `feat/library-ingestion` (pipeline
version **1**). An EPUB enters as an untrusted file and leaves as a
verified asset with reconciled bibliography, preserved structure, source
references and versioned artifacts, in state **READY_FOR_ENRICHMENT**.
Semantic enrichment (embeddings, summaries, entities, retrieval) belongs
to future milestones.

## Domain model

```
Work 1───n Edition 1───n BookAsset n───n BookSubmission
              │                │
              n                n
        Contributor      IngestionRun 1──n IngestionStageAttempt
        EditionIdentifier         1──n IngestionEvent (append-only)
```

- **Work** — the conceptual opus ("Il nome della rosa"). `provisional`
  until an admin confirms it.
- **Edition** — a specific published edition: language, publisher, dates,
  identifiers (ISBN-13/10, UUID, DOI, URI, other — raw form always kept),
  contributors with MARC relator roles on the pivot (`aut`, `trl`, …) and
  the credited name preserved per edition.
- **BookAsset** — one physical EPUB file. `sha256` (unique) identifies it;
  `content_sha256` fingerprints its normalized text. May exist without an
  Edition while ingestion is running.
- **BookSubmission** — the proposal of a file (upload or filesystem
  discovery). Approval lifecycle only: `pending_approval → approved |
  rejected | cancelled`. Ingestion state lives on the run, never mixed in.
- **IngestionRun** — one pipeline execution: `status` (queued, running,
  needs_review, failed, succeeded, cancelled) is separate from
  `current_stage` (hash, validate, parse, normalize, structure). Records
  pipeline version, priority, progress, heartbeat, correlation id.
- **IngestionStageAttempt** — audit row per stage execution: attempt
  number, handler version, duration, outcome, bounded error info.
- **IngestionEvent** — append-only timeline ("what happened to this
  EPUB?"); admin actions always carry the actor.
- **DuplicateCandidate** — content-duplicate signal for admins; never an
  automatic merge.
- **BookAccessGrant** — minimal access foundation: submitter of an
  approved book can see/download it; full collection ACLs come later.
- **SystemSetting** — persistent admin-editable settings
  (`submission_auto_approval`, default OFF, audited).

Identifiers: internal bigint PKs; every external identifier is an opaque
lowercase **ULID** in `public_id` (route binding + API `id`). Status
columns are strings validated by PHP enums — no PostgreSQL enum types, so
future stages/statuses need no schema surgery.

## State machine

```
submission: pending_approval ──approve/auto──▶ approved ─▶ run queued
                 │  └──reject──▶ rejected           │
                 └──cancel──▶ cancelled             ▼
run:   queued ─▶ running ─▶ (per stage) ─▶ succeeded = READY_FOR_ENRICHMENT
                  │   │
                  │   ├─ reviewable issue ─▶ needs_review ─ override/retry ─▶ queued
                  │   ├─ hard block / fatal ─▶ failed ─ admin retry ─▶ queued
                  │   └─ cancel_requested ─▶ cancelled (cooperative)
stages: hash ─▶ validate ─▶ parse ─▶ normalize ─▶ structure
```

- One job per stage (never one giant job): every boundary is a durable
  checkpoint, a cancellation point and a priority-preemption point.
- Transitions are transactional and produce events; `RunStateMachine` is
  the only writer of run statuses.
- Concurrency guards: partial unique indexes (one active run per
  submission and per asset) plus a per-run cache lock during execution.
- Retry: transient failures (worker unreachable, storage hiccup) retry
  with backoff up to `max_attempts_per_stage` (default 3); EPUB-fault
  failures never auto-retry. Admin retry resumes **from the blocked
  stage**, reusing all valid prior artifacts. Security validation
  failures are never overrideable.
- Stale recovery: the scheduler marks running runs with a silent
  heartbeat (default 30 min) as failed-retryable and requeues lost queued
  runs — never an automatic infinite loop.

## Stage responsibilities

| Stage | Executor | What happens |
|---|---|---|
| hash | Laravel (`HashStage`) | streaming SHA-256; exact dedup (existing asset adopted or run short-circuits); asset row created |
| validate | Python worker | zip/EPUB safety battery (see below); on success the file is **promoted** to content-addressed original storage |
| parse | Python worker | OPF metadata (EPUB 2/3) with raw provenance snapshot → `metadata.json`; normalized metadata onto the asset |
| normalize | Python worker | deterministic text extraction per spine item in reading order → `spine/NNNN.jsonl` nodes |
| structure | Python worker + Laravel | TOC (nav/NCX), sections, content fingerprint → `structure.json`; then Laravel reconciliation (Work/Edition, duplicates) |

Progress uses declared stage weights (hash 10, validate 15, parse 25,
normalize 25, structure 25) and is labeled *ingestion* progress — a
READY_FOR_ENRICHMENT book is structurally understood, not fully analyzed.

## Worker contract (`/internal/v1`)

- Laravel orchestrates; **Python transforms content and never touches
  domain tables or decides transitions** (invariant in AGENTS.md).
- Endpoints: `POST /internal/v1/epub/{validate,parse,normalize,structure}`
  with `{asset_ref, relative_path, artifact_dir, pipeline_version,
  source_sha256, correlation_id}` — public ids and data-root-relative
  paths only; the worker re-validates every path against the data root
  (realpath containment).
- Auth: `X-Mnemosyne-Internal-Token` shared secret from `.env`
  (`MNEMOSYNE_INTERNAL_TOKEN`); the worker fails closed (503) if unset.
- Envelope: `{status: passed|passed_with_warnings|needs_review|failed,
  stage, handler_version, duration_ms, issues[], result}` where each
  issue is `{code, severity: hard_block|reviewable|warning, message,
  overrideable, details}`. Full issue-code catalog:
  `services/ai-worker/README.md`.

## Safety battery (worker, validate + every zip access)

Path traversal & absolute entries, symlink entries, entry count cap,
per-entry and total uncompressed caps, compression-ratio bombs, encrypted
zip entries, duplicate names — all hard blocks. XML via defusedxml (no
external entities/DTD/expansion). Remote resources never fetched; scripts
stripped during normalization. DRM: `encryption.xml` covering content ⇒
`DRM_ENCRYPTED_CONTENT`, reviewable but **not overrideable** (no
bypassing); font obfuscation alone is only a warning. Limits are env-
tunable (`WORKER_MAX_*`, see worker README). `extractall()` is never
used; every member read is size-capped while streaming.

Hard security blocks can never be overridden — enforced twice (worker
marks `overrideable: false`; Laravel refuses to store overrides for
non-overrideable issues).

## Storage layout (all under the data root, DB stores relative paths)

```
library/incoming/{submission_ulid}/source.epub      upload/discovery quarantine
library/original/sha256/ab/cd/{sha256}.epub         immutable originals (2-level shard)
library/extracted/{asset_ulid}/v{pipeline}/         versioned artifacts
    manifest.json      per-stage handler versions, output hashes, warnings
    metadata.json      raw + normalized bibliographic metadata, provenance
    structure.json     TOC, sections, spine map, content fingerprint
    spine/0000.jsonl…  one node per line, reading order
```

Writes are atomic (temp file + rename). Originals are content-addressed
and never overwritten or deleted by the pipeline; artifacts are
regenerable per pipeline version (v2 can be built while v1 serves).
Retention of rejected/cancelled incoming files is a future policy —
nothing is auto-deleted today except the incoming file of a *successful*
run.

## Citation readiness

Every JSONL node carries: `node_id` (stable, derived from spine index +
ordinal), `spine_index`, `ordinal`, `type`, `text`, `heading_path`,
`source.href`, `source.fragment`, `lang`, `char_count`. Sections map node
ranges. Future retrieval must keep this chain (AGENTS.md invariant):
BookAsset → spine item → source file → fragment → ordinal. EPUB CFI is
not generated in v1 (never invented — left null by design).

## Deduplication & reconciliation

- **Exact**: same `sha256` ⇒ same asset; one physical file, N
  submissions/provenance records, submitter granted access, no
  reprocessing (only a hash attempt on the new run).
- **Content**: same `content_sha256` (fingerprint v1 = sha256 of
  normalized block text in reading order; cover/CSS/metadata/packaging
  independent) across different files ⇒ open `DuplicateCandidate` with
  metadata comparison evidence; the twin's Edition is adopted (labeled
  `exact` via `content_fingerprint`) but assets are never merged or
  deleted — admin decides.
- **Bibliographic**: conservative, versioned, reversible. Confidence
  labels only (`exact`, `high_confidence`, `candidate`, `unresolved`) —
  no invented percentages. Auto-link requires ISBN + normalized-title
  agreement, or title+creator+language agreement; anything else creates a
  provisional Work/Edition recording method + evidence.

## Queues & operations

- Queues `ingestion-high|normal|low`; Horizon `supervisor-ingestion`
  drains them strictly in that order (`balance: off`), concurrency from
  `MNEMOSYNE_INGESTION_CONCURRENCY` (default 2 — shared host).
- Priority changes are audited and apply from the next stage boundary.
- Scheduler: heartbeat every minute (feeds the scheduler container
  healthcheck `mnemosyne:scheduler:healthcheck`) and
  `mnemosyne:ingestion:detect-stale` every 5 minutes.
- Upload limits: application-level `MNEMOSYNE_MAX_UPLOAD_BYTES` (default
  150 MB) + free-disk guard; PHP/nginx body limits must be ≥ the
  application value (see runbook).
- Bulk import foundation: `mnemosyne:library:discover` streams an
  allowlisted root (`MNEMOSYNE_IMPORT_SOURCES`), dry-run/limit flags,
  symlink and containment safety, Author/Title path *hints* only. The
  real 100k library scan runs only when its bind mount and source entry
  are configured; large-scale hard-linking and a resumable DiscoveryRun
  model are documented follow-ups.

## Versioning

`pipeline_version` (currently "1") names the artifact set; each stage
attempt records its handler version (worker constants, currently all
1.0.0). Bump a handler version when its behavior changes; bump the
pipeline version to regenerate artifacts side by side. The fingerprint
carries its own `content_fingerprint_version`. Future stages (enrich →
chunk → embed → summarize → entities → relationships → index → verify →
ready) extend `IngestionStage` — never fake handlers.

## Testing map

- `tests/Feature/Library/*`, `tests/Feature/Ingestion/*`,
  `tests/Feature/Api/*` — host suite (sqlite, worker faked at the HTTP
  boundary with real envelope shapes).
- `tests/Integration/*` — `make test-integration`: ephemeral PostgreSQL
  (`pg-test`, tmpfs, name guarded to `*_test`) and the **real** worker
  (`ai-worker-test`) on loopback; includes the acceptance E2E (synthetic
  EPUB → READY_FOR_ENRICHMENT with artifact/citation assertions),
  duplicate E2Es, malformed/traversal/DRM E2Es, and an N+1 guard.
- Python: `make test-python` — 72 tests incl. the safety battery and
  fingerprint determinism. Synthetic EPUBs only; no copyrighted fixtures.
