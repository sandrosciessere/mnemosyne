# Milestone 2 independent review — backlog closure

Permanent checklist. Every finding of the final independent Milestone 2
review must end in exactly one disposition: `FIXED` ·
`ALREADY_SUPERSEDED` · `NOT_REPRODUCIBLE_WITH_EVIDENCE` ·
`DEFERRED_WITH_EXPLICIT_OWNER_REASON`. Closed in the M3 third
corrective pass on `feat/grounded-answers-reader`.

Provenance of the list: the review left F1–F10 named in
`docs/requirements/mvp-v1.1-traceability.md` plus a set of LOW findings
without repository IDs; the LOW findings are numbered F11–F23 here in
the order the review reported them (chunk hard-max edge, API excerpt
offsets, exact cap/truncation/NFD, API/neighbor throttling,
superseded-generation neighbor coherence, auth test gaps, rerank M cap,
sub-3 exact scale, stale docs, reranker model revision snapshot,
pgvector pin, lexical config hard-coded). No original finding is
omitted.

Test file: `apps/web/tests/Feature/Retrieval/M2BacklogClosureTest.php`
(+ PG integration where noted).

| ID | Finding | Disposition | Evidence |
|---|---|---|---|
| F1 | OpenAPI reranker fallback/timeout enum incomplete | FIXED | `docs/api/openapi.yaml` meta: `reranker_attempted`, `reranker_used`, `reranker_fallback_reason` enum `[timeout, worker_unavailable, reranker_error, empty_scores, partial_scores]` — mirrors `HybridSearchService` runtime values |
| F2 | `reranker_used=true` on HTTP 200 with empty/malformed/non-finite scores | FIXED | `WorkerRerankerProvider` accepts only genuine finite numbers; `HybridSearchService` requires usable scores for ≥50% of the head else `reranker_used=false` + `empty_scores`/`partial_scores`; tests `test_f2_*` |
| F3 | Exact boundary guarantee coupled to live config | FIXED | `HybridSearchService::maxExactPhraseChars($generation)` derives the cap from the generation's snapshotted `overlap_tail_chars` (min with live cap); controller + answer packet builder use it; test `test_f3_*` |
| F4 | Generation snapshot coupling (chunk boundary parameters) | FIXED | same mechanism as F3 — the exact window is a property of the indexed generation; historical generations keep coherent behavior |
| F5 | Unicode recall-only mismatch (NFD literal vs NFC source) | FIXED | `ExactRetriever` NFC-normalizes the PHRASE only; source coordinates untouched (located in original string); test `test_f5_*` proves recall + exact offsets |
| F6 | Case-insensitive Unicode transforms (recall) | FIXED / ALREADY_SUPERSEDED | offsets were already located in the original string (pass 1); PG `ILIKE` handles Unicode folding; NFC normalization closes the remaining recall gap; covered by F5 test + existing `ExactUnicodeOffsetsTest` |
| F7 | Lexical fallback visible only in admin debug | FIXED | `meta.lexical_strategy` (`strict`/`or_fallback`/`none`) + `meta.lexical_fallback_used` for every caller; OpenAPI updated; test `test_f7_*` |
| F8 | Concurrent activation loser raw 500 | FIXED | `RetrievalGenerationManager::activate` catches `UniqueConstraintViolationException` → `InvalidTransitionException('GENERATION_ACTIVATION_CONFLICT')`; one-active invariant unchanged; test `test_f8_*` |
| F9 | `source_content_sha256` write-once poisons re-ingested assets | FIXED | `RetrievalIndexer::indexAsset` re-keys the asset state to the new fingerprint and rebuilds (delete+rebuild chunks) instead of permanent `SOURCE_HASH_MISMATCH`; historical grounded-answer evidence keeps its own fingerprint → `CITATION_SOURCE_CHANGED` (fail-closed); test `test_f9_*` |
| F10 | Ready assets during a building generation are missed | FIXED | `enqueueForActiveGeneration` enqueues into ACTIVE and every BUILDING generation (isolation preserved: one job per generation); test `test_f10_*` |
| F11 | Chunk hard-max edge (sub-min buffer + large piece could exceed max) | FIXED | `Chunker` closes a sub-min buffer when the incoming piece alone is ≥ min; test `test_f13_*` (numbering in code comments refers to the review's "hard max" item) |
| F12 | API excerpt offset semantics ambiguous | FIXED | `excerpt_start_in_chunk`, `excerpt_start/excerpt_end` on exact matches, `coordinate_systems` contract in result + OpenAPI; test `test_f5_*` asserts the arithmetic |
| F13 | Exact result cap / truncation / NFD | FIXED | `meta.exact_truncated` (limit+1 probe), NFC normalization (F5), OpenAPI text; tests `test_f17_*`, `test_f5_*` |
| F14 | API / neighbor endpoint throttling | FIXED | `throttle:retrieval-neighbors` (120/min/user — generous vs reader usage) added to the neighbors route; test `test_f18_*` |
| F15 | Superseded-generation neighbor coherence | NOT_REPRODUCIBLE_WITH_EVIDENCE → guarded | neighbors resolve within `chunk.retrieval_generation_id` (never the active generation); regression `test_f19_*` proves it under supersession; answer citations resolve via canonical coordinates only |
| F16 | Auth test gaps for M2 APIs | FIXED | `test_f20_m2_api_auth_matrix`: unauthenticated 401, bearer path, cross-user 403 without leakage, foreign scope 403 (plus existing `StatefulApiAuthenticationTest` for session+CSRF) |
| F17 | Rerank M unbounded | FIXED | `retrieval.search.rerank_hard_max` (50) caps `rerank_top_m` regardless of config; test `test_f21_*` |
| F18 | Sub-3 exact query scaling | FIXED (explicit) | served, bounded by the candidate cap, flagged (`exact_short_query` diagnostic) and documented in OpenAPI; no global scans introduced; test `test_f22_*` |
| F19 | Stale retrieval docs | FIXED | `docs/architecture/retrieval.md` + OpenAPI updated (generation-derived exact cap, NFC, truncation, reranker truthfulness/attempted flag, lexical strategy visibility, hard cap, pgvector pin, lexical config ownership) |
| F20 | Reranker model revision snapshot | ALREADY_SUPERSEDED / verified | worker returns `model_identity {hf_id, revision}`; `HybridSearchService` records `reranker_model` in diagnostics whenever reranking is attempted (also on unusable output); the answer pipeline persists retrieval diagnostics per run — reranking is OFF by default in all M3 policies |
| F21 | pgvector image floating | FIXED | `compose.yaml` pins `pgvector/pgvector:pg17@sha256:7ae6051e…` (the exact digest currently running: PostgreSQL 17.10 + pgvector 0.8.6); no restart/major upgrade performed |
| F22 | Lexical config hard-coded | FIXED | `retrieval.lexical_config` (default `simple`) snapshotted into the generation profile; `LexicalRetriever::tsConfigFor($generation)` reads it back through an allowlist (`simple/english/italian/french/german/spanish`) — index and query side always agree |
| F23 | (review's residual LOW: chunk/excerpt wording ambiguity) | ALREADY_SUPERSEDED | M3 citations use durable canonical answer-evidence coordinates (pass 1) and the API now states its coordinate systems explicitly (F12) |

Open items: **NONE**. Milestone 1 backlog was NOT touched by this pass
(no M2/M3 fix required it); it remains tracked in the traceability
matrix as `DEFERRED_WITH_REASON`.
