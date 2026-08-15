# Retrieval foundation (Milestone 2)

Status: implemented by `feat/retrieval-foundation`. Transforms Milestone 1
structural artifacts into ranked, provenance-exact evidence. **No answer
generation** — that is Milestone 3.

## Data model

```
BookAsset (M1, authoritative)
   │  canonical.txt + spine/*.jsonl (source nodes, offsets, source_hash)
   ▼
RetrievalGeneration (immutable-once-active component profile)
   │ 1:n
RetrievalAssetState (per-book indexing lifecycle: pending → chunking →
   │                  embedding → ready | failed)
   ▼
RetrievalChunk ──1:n── RetrievalEvidenceSpan (provenance)
   │ 1:1
RetrievalEmbedding (pgvector, per-generation partial HNSW)
```

Key distinction (never blurred):
- **source text** — M1 canonical/node text, the only quotable material;
- **chunk** — retrieval unit; `source_text` contains ONLY source-backed
  characters plus the canonical `\n` separators;
- **index/embedding text** — `heading_text + "\n\n" + source_text`
  (context that improves dense recall; never citable);
- **EvidenceSpan** — maps a chunk region to exact source coordinates;
- **result** — ranked chunk + spans + score provenance.

## EvidenceSpan

Fields: chunk FK, span_ordinal, source_node_id, spine_index, href,
fragment, node_type, heading_path, canonical_start/end (codepoints),
utf16_start/end (code units — future JS reader), chunk_start/end,
source_hash (M1 stale-citation hash). Invariants (tested in three
coordinate systems, incl. emoji/astral/CJK):
`canonical[start:end] == chunk[chunk_start:chunk_end] ==
utf16_slice(utf16_start, utf16_end)`. Chunk regions covered by no span
are synthetic separators and are never presented as evidence.

## Chunker (v1.0.0)

Deterministic structural chunking of the spine JSONL nodes
(config snapshot in the generation; defaults target 1200 / min 250 /
max 2200 / overlap 200 chars — derived from real node stats, avg 427,
p99 ≈ 2000, and e5's 512-token window):
- spine-document boundary ALWAYS closes a chunk (no cross-chapter blending,
  no cross-document overlap);
- a heading closes a ≥min buffer; target closes a chunk; hard max bounds
  the content region, splitting oversized nodes at sentence boundaries
  losslessly (sub-spans of the same node);
- **provenance-aware overlap**: the previous chunk's tail sentences
  (≤ overlap chars) are repeated at the next chunk's start as fully
  mapped spans (`overlap_prefix_chars`); a literal phrase straddling the
  partition boundary therefore exists intact in one chunk (regression-
  tested), and final selection dedupes by span overlap;
- chunk fingerprint `content_sha256` = sha256(span provenance + text):
  same source + config ⇒ identical chunks (tested across generations);
- image-only/empty nodes are skipped with counters; no OCR, no invented
  text.

## Retrievers

- **Exact**: parameterized LIKE/ILIKE (wildcards escaped) over
  `source_text`, backed by a `pg_trgm` GIN index; per-match offsets are
  located **in the original source string** (`mb_stripos` for the
  case-insensitive mode — Unicode case folding is not length-preserving,
  e.g. İ → i+U+0307, so offsets are never derived from a folded copy)
  and mapped to canonical coordinates via the spans; a defense-in-depth
  check skips any candidate whose original slice does not fold-equal the
  phrase. Case-sensitive mode uses LIKE; insensitive uses ILIKE. The
  matched substring always equals the request literal under the selected
  case semantics.
  **Boundary guarantee**: a literal straddling the chunk partition is
  intact in one chunk iff its pre-boundary portion fits the chunker
  overlap; accepted exact phrases are therefore capped at the
  GENERATION's snapshotted `overlap_tail_chars` (200 in the current
  generation — derived via `HybridSearchService::maxExactPhraseChars`,
  never from mutable live config alone). Longer exact-mode queries get
  422 `EXACT_QUERY_TOO_LONG`; hybrid skips the exact component with
  `meta.exact_skipped_reason` — never a silent false-negative window.
  Literals are NFC-normalized before matching (recall for NFD input;
  source coordinates are never altered); `meta.exact_truncated` says
  when more chunks matched than the candidate cap returned; sub-3-char
  literals are served but flagged (low precision, no table scans added).
  Result payloads state their coordinate systems explicitly
  (`excerpt_start_in_chunk`, excerpt-relative offsets on exact matches).
- **Lexical** (v1.1.0): generated weighted tsvector (`'simple'` config —
  language-agnostic, multilingual; heading=A, body=B) + GIN;
  `websearch_to_tsquery` (never string-built tsquery);
  `ts_rank_cd(..., 32)` scores in (0,1) — not probabilities.
  Two-stage strategy: strict websearch query first; when it yields zero
  rows (natural-language queries — 'simple' keeps function words that
  strict AND semantics require), a fallback ORs the meaningful query
  tokens (\p{L}\p{N} only, ≥3 chars, deduped, capped at 12; still one
  bound parameter — no tsquery injection). The strategy used is exposed
  to EVERY caller as `meta.lexical_strategy` (`strict` / `or_fallback` /
  `none`) + `meta.lexical_fallback_used`. The text-search configuration
  is owned by the generation profile (`lexical.config`, allowlisted;
  index and query side always agree). Generations snapshotting lexical
  1.0.0 keep strict-only behavior.
- **Dense**: multilingual-e5-small (384d, cosine, normalized) served by
  the local worker; query embeds with `query:` prefix, documents with
  `passage:`. SQL orders by `embedding::vector(384) <=> query` under the
  generation's **partial expression HNSW index**; scope filter in SQL
  with configurable overfetch + pgvector 0.8 `hnsw.iterative_scan` to
  counter filtered-ANN under-return. `relaxed_order` scans do not
  guarantee globally sorted rows, so overfetched candidates are
  explicitly re-sorted by distance (deterministic id tie-break) before
  dense ranks are assigned. Wrong-dimension vectors are rejected at
  write time by the expression index (DB backstop below the provider
  validation).

## Fusion, reranking, selection

- **Weighted RRF** (v1, k=60; weights exact 2.0 / lexical 1.0 /
  dense 1.0): rank-based — component scores are never pretended
  comparable; deterministic tie-break (asset, ordinal). RRF score is not
  a probability.
- **Reranker**: mmarco-mMiniLMv2 cross-encoder on the top M=24 fused
  candidates via the worker (hard safety cap 50 regardless of config).
  Truthful reporting: `reranker_attempted` vs `reranker_used` — an
  HTTP 200 with empty/malformed/non-finite scores does NOT count as
  reranked (`reranker_fallback_reason` = `empty_scores`/`partial_scores`);
  the worker's model identity (hf_id + revision) is recorded whenever
  reranking is attempted. **Opt-in** (`rerank` defaults to false):
  it adds seconds of CPU latency for a mixed quality delta, so the
  default query path never pays it. Runs under its own dedicated
  timeout (`retrieval.reranker.timeout_seconds`, default 30 s — not the
  general ~330 s worker timeout); timeout/failure degrades to fused
  order with `reranker_used=false` + a specific reason (`timeout`,
  `worker_unavailable`, `reranker_error`) — never silently pretended.
  Known limitation: candidates longer than the model's 512-token window
  are truncated, so evidence near the end of maximum-size chunks can be
  under-scored.
- **Coverage selection**: greedy by rank; a candidate whose canonical
  interval overlaps an already-selected chunk of the same asset ≥60% is
  dropped (provenance overlap, not string equality) — the deliberate
  chunk overlap never yields near-duplicate result lists. Neighbors
  (prev/next chunk) are context expansion, exposed separately and never
  mixed into ranking.

## Generations (blue/green)

A generation snapshots chunker version+config(+hash), lexical & query-
normalization versions, embedding identity (model_key, hf_id, pinned
revision, dims, metric, normalization), fusion and reranker profiles.
Building generations index side-by-side with the active one (newly
ready books are enqueued into the active AND every building
generation, so a build converges on the complete eligible set);
activation is one transactional flip (previous → superseded, data
preserved; a concurrent second activation loses with a controlled
`GENERATION_ACTIVATION_CONFLICT`, never a 500); queries execute against
exactly one generation; per-generation partial ANN indexes make
cross-generation vector mixing physically impossible. Source-changed
lifecycle: when an asset's canonical fingerprint changes (legitimate
re-ingest), its retrieval state is re-keyed and rebuilt for the new
source; historical grounded-answer citations keep their own fingerprint
and go stale fail-closed. Storage: `pgvector/pgvector:pg17` is pinned by
digest (PostgreSQL 17.10 + pgvector 0.8.6).

## API authentication

`/api/v1` accepts two first-party authentication paths: Sanctum
**stateful SPA sessions** (browser: session cookie + `X-XSRF-TOKEN`,
enabled by `Middleware::statefulApi()` in `bootstrap/app.php`; stateful
hosts derive from `APP_URL`, override with `SANCTUM_STATEFUL_DOMAINS`)
and Sanctum **bearer tokens**. Same-origin browser requests keep full
CSRF protection through the stateful middleware; anonymous requests get
401 JSON.

## ACL & failure semantics

Authorization is resolved server-side BEFORE retrieval (grants ∪ admin);
requesting any inaccessible/unknown asset ULID fails closed (403, no
oracle); chunk/span endpoints re-check asset authorization; possession
of ULIDs bypasses nothing. Granted-but-not-indexed assets are reported
in `meta.skipped_assets` — partial evidence is never silent. Dense
outage: pure dense mode errors; hybrid degrades explicitly
(`dense_unavailable`). Indexing failures are classified (transient →
bounded backoff requeue; configuration/source-hash → permanent) and
never touch Milestone 1 ingestion state.
