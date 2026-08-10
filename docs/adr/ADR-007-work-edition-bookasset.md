# ADR-007 — Work / Edition / BookAsset bibliographic model

**Status**: accepted (2026-08-10)

## Context

Hundreds of thousands of EPUBs will include the same opus in many
editions and the same edition in many files (re-downloads, different
covers, repackagings). A flat "book = file" model cannot express
deduplication, reconciliation or future multi-edition analysis.

## Decision

Three distinct entities: `Work` (conceptual opus) → `Edition` (specific
published edition, with contributors via MARC relator roles and
normalized identifiers) → `BookAsset` (one physical file, `sha256`
unique, optional `content_sha256` fingerprint). Assets may exist without
an Edition during ingestion. Automatic reconciliation is conservative,
labeled (`exact | high_confidence | candidate | unresolved`), records
method + evidence + version, and creates `provisional` rows instead of
guessing. Content-fingerprint matches produce `DuplicateCandidate` rows
for human resolution — never automatic merges.

## Consequences

- Exact dedup by hash; "same text, different cover" becomes a reviewable
  signal instead of a silent duplicate or a destructive merge.
- Admins can later confirm/split provisional Works without data loss
  (raw metadata and credited names survive per edition).
- Slightly more joins than a flat model — mitigated by bigint keys and
  targeted indexes.
