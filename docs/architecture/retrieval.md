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
  located in the chunk and mapped to canonical coordinates via the
  spans. Case-sensitive mode uses LIKE; insensitive uses ILIKE. The
  matched substring always equals the request literal.
- **Lexical**: generated weighted tsvector (`'simple'` config —
  language-agnostic, multilingual; heading=A, body=B) + GIN;
  `websearch_to_tsquery` (never string-built tsquery);
  `ts_rank_cd(..., 32)` scores in (0,1) — not probabilities.
- **Dense**: multilingual-e5-small (384d, cosine, normalized) served by
  the local worker; query embeds with `query:` prefix, documents with
  `passage:`. SQL orders by `embedding::vector(384) <=> query` under the
  generation's **partial expression HNSW index**; scope filter in SQL
  with configurable overfetch + pgvector 0.8 `hnsw.iterative_scan` to
  counter filtered-ANN under-return. Wrong-dimension vectors are
  rejected at write time by the expression index (DB backstop below the
  provider validation).

## Fusion, reranking, selection

- **Weighted RRF** (v1, k=60; weights exact 2.0 / lexical 1.0 /
  dense 1.0): rank-based — component scores are never pretended
  comparable; deterministic tie-break (asset, ordinal). RRF score is not
  a probability.
- **Reranker**: mmarco-mMiniLMv2 cross-encoder on the top M=24 fused
  candidates via the worker; timeout/failure degrades to fused order
  with `reranker_used=false` + reason (never silently pretended).
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
Building generations index side-by-side with the active one; activation
is one transactional flip (previous → superseded, data preserved);
queries execute against exactly one generation; per-generation partial
ANN indexes make cross-generation vector mixing physically impossible.

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
