# ADR-009 — Content-addressed originals and versioned extracted artifacts

**Status**: accepted (2026-08-10)

## Context

Originals must be immutable, deduplicated and safe from filename
collisions at 100k+ scale; extracted text must be regenerable when
parsers improve, without databases full of duplicated blobs.

## Decision

Originals live at `library/original/sha256/aa/bb/{sha256}.epub`
(two-level shard, atomic promote after the validate stage, never
overwritten or deleted by the pipeline). Extracted artifacts live at
`library/extracted/{asset_ulid}/v{pipeline_version}/` as `manifest.json`
(stage handler versions, output hashes, warnings), `metadata.json` (raw +
normalized), `structure.json` (TOC/sections/fingerprint) and
`spine/NNNN.jsonl` (one text node per line with source references). The
database stores metadata, structure summaries, indexes and relative
paths — never the full text of books. All writes are temp-file +
atomic-rename.

## Consequences

- Exact duplicates cost zero extra bytes; no directory ever holds
  millions of entries; user filenames never touch the filesystem layout.
- Pipeline v2 artifacts can be built alongside v1 (blue/green) and the
  manifest makes stage idempotency and debugging auditable.
- A future move of the library is a bind-mount change (ADR-003 still
  holds); reprocessing never needs the original re-uploaded.
