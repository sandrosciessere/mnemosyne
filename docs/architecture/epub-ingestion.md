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
run:   queued ─▶ running ─▶ (per stage) ─▶ succeeded
                  │   │
                  │   ├─ reviewable issue ─▶ needs_review ─ override/retry ─▶ queued
                  │   ├─ hard block / fatal ─▶ failed ─ admin retry ─▶ queued
                  │   ├─ admin pause ─▶ paused ─ resume ─▶ queued (durable checkpoint)
                  │   ├─ admin "mark unsupported" (from failed/needs_review/paused) ─▶ skipped
                  │   └─ cancel_requested ─▶ cancelled (cooperative)
asset: pending ─▶ processing ─▶ ready_for_enrichment
                              ├▶ ready_for_enrichment_with_warnings  (recoverable warnings occurred)
                              ├▶ needs_review / failed
                              └▶ unsupported                          (admin skip decision)
stages: hash ─▶ validate ─▶ parse ─▶ normalize ─▶ structure
```

Pause is cooperative and two-level, both persisted and audited:
- **Per-run**: a running stage finishes safely; nothing further is
  dispatched. Resume re-dispatches from the durable checkpoint
  (current stage, or the next one when its attempt already succeeded).
- **Global** (`ingestion_paused` system setting): dispatch becomes a
  no-op everywhere and stage boundaries park runs back in `queued`;
  global resume re-dispatches every queued run. Survives restarts.
"Replace file" is intentionally NOT an in-place operation: a corrected
EPUB arrives as a new submission (new sha → new immutable asset); the
broken one is marked unsupported.

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

## Source fidelity & citation readiness

Four representations are kept separately, none substituting another:

1. the **immutable original EPUB** (content-addressed);
2. **sanitized source XHTML** per spine document
   (`sanitized/NNNN.xhtml`): scripts/event-handlers/remote references
   stripped, ids/anchors/`epub:type`/lang preserved, traceable via
   `data-mnemosyne-source-href` + `data-mnemosyne-spine-index`;
3. the **canonical normalized text** (`canonical.txt`) — exactly the
   fingerprint corpus, so `sha256(canonical.txt) == content_sha256`;
4. **structural node metadata** (`spine/NNNN.jsonl`).

Every JSONL node carries: `node_id` (stable), `spine_index`, `ordinal`,
`type`, `text`, `heading_path`, `source.href`, `source.fragment`, `lang`,
`char_count`, plus the EvidenceSpan foundations:

- `normalized_start` / `normalized_end` — Unicode codepoint offsets into
  `canonical.txt` with the tested invariant
  `canonical_text[start:end] == node.text` (null for nodes excluded from
  the fingerprint corpus: figure alt-only nodes, non-linear docs);
- `source_hash` — sha256 of `href \0 fragment \0 type \0 text`: stable
  across artifact regeneration while the source location+content is
  unchanged, so future citations can detect staleness after parser or
  pipeline upgrades;
- `refs` — internal link/noteref targets (`{kind, href, fragment}`),
  `is_note` for footnote/endnote bodies, structured `table`
  (`{caption, rows}`) on table nodes and `image` (`{href, alt}`) on
  figures/SVG — reader navigation and note traversal stay possible
  without semantic interpretation.

Sections map node ranges. Future retrieval must keep this chain
(AGENTS.md invariant): BookAsset → spine item → source file → fragment →
ordinal/offsets. EPUB CFI is not generated in v1 (never invented — left
null by design).

## Compatibility matrix (first-milestone acceptance gate)

`tests/Integration/CompatibilityMatrixTest.php` pushes ten heterogeneous
synthetic fixtures through the REAL pipeline (PostgreSQL + Python
worker), plus the hostile set. All fixtures are generated —
no copyrighted content.

| # | Fixture | Exercises | Expected |
|---|---|---|---|
| 1 | `epub2` | EPUB 2 + NCX, Italian | ready |
| 2 | `epub3` | EPUB 3 + nav, ISBN/UUID, roles | ready |
| 3 | `nestedHeadings` | h1→h4 hierarchy, heading paths | ready |
| 4 | `manySpineDocuments` | 8 spine docs, reading order | ready |
| 5 | `richContributors` | 2×aut + edt + ill, file-as | ready |
| 6 | `multilingual` | multi dc:language, per-block xml:lang, Greek/Cyrillic/CJK offsets | ready |
| 7 | `footnotes` | noterefs, aside footnotes, cross-doc links | ready |
| 8 | `tablesAndCaptions` | caption/thead/th structure, figcaption | ready |
| 9 | `svgAndImages` | inline SVG title, img alt, surrounding prose | ready |
| 10 | `recoverableXhtml` | HTML-style tags → fallback parser | ready **with warnings** |
| — | `remoteAndScript` | remote refs + scripts | ready with warnings; sanitized artifact clean |
| — | `malformed`, `invalidOpfXml` | broken container/OPF | failed |
| — | `pathTraversal` | zip escape | failed, `ZIP_PATH_TRAVERSAL`, never overrideable |
| — | `encryptedContent` | DRM | needs_review, never overrideable |
| — | `missingResource` | dangling spine ref | needs_review, overrideable → ready with warnings |

Zip-bomb and oversize limits are covered by the worker's Python unit
suite (limits are monkeypatched there; crafting real bombs in the
integration fixtures would be wasteful).

## Deduplication & reconciliation

Reconciliation is deliberately conservative: it prefers duplicate rows and
open review candidates over any silent merge, and it keeps **identity**
strictly separate from **matching evidence**. A normalized name or title is
only evidence that records *may* relate — never a global identity key.

- **Exact**: same `sha256` ⇒ same asset; one physical file, N
  submissions/provenance records, submitter granted access, no
  reprocessing (only a hash attempt on the new run).
- **Content**: same `content_sha256` (fingerprint v1 = sha256 of
  normalized block text in reading order; cover/CSS/metadata/packaging
  independent) across different files ⇒ open `DuplicateCandidate` with
  metadata comparison evidence. **A fingerprint match alone never
  establishes Edition identity** (Path B below). Conflicting metadata keeps
  the assets on distinct provisional editions with the candidate left open.
  Assets are never merged or deleted automatically.

**Evidence classification.** Each strong dimension of an incoming asset is
classified against a candidate edition as **AGREE**, **ABSENT**, or
**CONFLICT** — missing data is always ABSENT, *never* affirmative
agreement. Dimensions: `title`, `creator`, `language`, `identifier`
(canonical ISBN / doi / uuid), `publisher_year`. Evidence payloads record
the per-dimension verdict explicitly (e.g. `{"language":"absent"}`).

**Edition auto-link is allowed on exactly two paths, each requiring NO
dimension in CONFLICT:**

- **Path A — canonical identifier.** A trusted canonical ISBN-13 matches an
  existing edition *and* the title AGREEs. ISBN-10 and its ISBN-13
  equivalent are the same identifier: identifiers store both the declared
  `scheme`/`value` and a `canonical_scheme`/`canonical_value` (the worker
  derives `isbn13` for a valid ISBN-10), and matching compares canonical
  values. Labeled `high_confidence` via `identifier_and_title`.
- **Path B — content fingerprint.** A `content_sha256` twin whose `title`,
  `creator`, and **explicit** `language` all AGREE. Labeled
  `high_confidence` via `content_fingerprint_with_bibliographic_agreement`.

On adoption the incoming asset's identifiers are attached to the adopted
edition (canonical forms included).

**Work vs Edition.** Title + creator agreement is evidence of the same
**Work**, never automatically the same **Edition**. Two legitimate editions
of one work are kept as *Work → Edition A + Edition B*, never collapsed. A
title+creator match therefore reuses the Work and mints a *distinct
provisional Edition*; if a sibling edition's strong metadata CONFLICTS
(e.g. same title+creator+language but ISBN A vs ISBN B), a
`bibliographic_conflict` `DuplicateCandidate` is opened for review — never
an auto-merge.

**Provisional contributor identity.** A normalized-name match is evidence
for future authority resolution, not identity. Absent strong corroborating
evidence (authority ids — none in metadata yet), every bibliographic credit
becomes a fresh `Contributor` row; two unrelated "John Smith" credits yield
two rows. `normalized_name` stays populated for search/candidate matching.

**Filename titles never establish identity.** A missing `dc:title` falls
back to the filename as a *display* title only (`title_source =
'filename'`, recorded in evidence + `source_metadata`); it never
participates in Work/Edition matching, forcing the provisional path.

Confidence is a label only (`exact`, `high_confidence`, `candidate`,
`unresolved`) — no invented percentages. Anything weaker than Path A/B
creates a provisional Work/Edition recording method + evidence. Duplicate
candidates are stored as a **canonical unordered pair**
(`asset_low_id`/`asset_high_id` with a DB-enforced symmetric unique), so
`(A,B)` and a reversed/concurrent `(B,A)` converge on one row. Everything
is versioned and reversible.

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
- Bulk import is TWO separated phases over an allowlisted root
  (`MNEMOSYNE_IMPORT_SOURCES`):
  1. `mnemosyne:library:discover --source=<name>` — **strictly read-only**
     scan into a persistent manifest (`DiscoveryRun` + `DiscoveryEntry`):
     no copies, no submissions. Deterministic sorted DFS with bounded
     memory, symlink/containment safety, unreadable-dir counting,
     Author/Title path *hints*, batch-persisted counters and a durable
     `last_path` cursor — an interrupted 100k+ scan resumes with
     `--resume=<run>` instead of restarting (idempotent: per-run unique
     entries + insertOrIgnore).
  2. `mnemosyne:library:import <run> [--priority] [--limit]` — consumes
     `discovered` entries: re-validates each source path (symlink/
     containment/existence), copies it into the incoming quarantine
     (atomic), and creates the submission in the same transaction that
     flips the entry to `imported` — re-running after any interruption
     never duplicates submissions. Failures land in `import_failed` with
     the error recorded.
  The real 100k library scan runs only when its bind mount and source
  entry are configured; large-scale hard-linking remains a follow-up.

- **Byte-exact paths (non-UTF-8 safe).** ext4 path components are arbitrary
  bytes; real libraries carry latin-1 filenames. The AUTHORITATIVE
  `discovery_entries.relative_path` stores the **base64 of the raw path
  bytes** — lossless, ASCII, bounded (fits varchar+btree), and byte-distinct,
  so `caf\xe9.epub` and `caf\xe8.epub` remain two entries (a lossy `mb_substr`
  mangled both to `caf?.epub`, silently dropping one via the per-run unique).
  Import decodes it back to the exact bytes to locate the file, then re-runs
  the symlink/realpath/containment check on those raw bytes. A separate
  best-effort `display_path` (valid UTF-8, invalid bytes → U+FFFD) and the
  Author/Title hints are display-only — PostgreSQL text columns cannot store
  invalid UTF-8, and `source_reference` JSON carries `display_path` plus
  `relative_path_b64`, never raw bytes. The resume cursor (`last_path`) is
  base64 too. Traversal order is byte-wise (`scandir(SCANDIR_SORT_NONE)` +
  `usort(strcmp)`), locale-independent by invariant so it always matches the
  `pathCompare` cursor comparator even after `setlocale`.
- **Resume safety.** `--resume` refuses when the freshly-resolved source root
  differs from the run's persisted `root_path` (never replay a cursor from
  root A against root B). `--dry-run --resume` previews remaining work and
  persists nothing about lifecycle (no status flip, no counter writes).
  `files_seen`/`unreadable` are SET (re-derived by re-walking from the root),
  not incremented, so resumes never double-count.
- **Quarantine hygiene.** Import copies into `library/incoming/{ulid}/` BEFORE
  the DB claim; EVERY failure path (containment reject, ENOSPC/copy throw,
  submission throw, concurrent-claim loss) deletes the staging dir it created,
  and a claim loser does not inflate the `imported` counter. Before staging,
  each entry checks the same near-full-disk guard as uploads
  (`min_free_disk_bytes`); insufficient space is a **retryable** failure that
  stops the run rather than spinning. `--retry-failed` returns
  `import_failed` entries to `discovered` — **except** security/containment
  failures, which are tagged (`SECURITY:` reason prefix) and never
  auto-retried. Whatever still slips through a hard crash is swept by
  `mnemosyne:ingestion:cleanup` (see runbook).

## Idempotency audit (per stage)

| Stage | Durable input | Durable output | Idempotency strategy | Retry class |
|---|---|---|---|---|
| hash | incoming file | BookAsset row (sha unique), submission/run links | `sha256` unique constraint + already-linked short-circuit; re-hash of the same bytes converges on the same asset | I/O errors retryable; missing file fatal |
| validate | incoming/original file | verdict + promotion to content-addressed original | promotion checks destination first (originals never overwritten), temp+rename atomic; re-run is a no-op | transport retryable; verdicts final |
| parse | original file | `metadata.json`, `extracted_metadata` | artifact rewrite is atomic + deterministic; DB update idempotent | transport retryable |
| normalize | original file | `spine/*.jsonl`, `sanitized/*.xhtml`, `canonical.txt` | whole-book regeneration, atomic per file, byte-deterministic (tested) | transport retryable |
| structure | spine JSONL | `structure.json`, fingerprint, reconciliation | reads its own prior artifacts; reconciliation re-entry guarded (`edition_id` already set → keep links; candidates unique-keyed) | transport retryable |

Cross-cutting: one job per stage with per-run cache lock; partial unique
indexes forbid concurrent runs per submission/asset; attempts are
append-only rows keyed `(run, stage, attempt)`; every transition is one
transaction; a worker that dies mid-stage leaves artifacts that the
retry simply rewrites (regression-tested by the retry/pause/duplicate
suites).

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
