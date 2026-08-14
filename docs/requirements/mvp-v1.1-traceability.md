# MVP v1.1 requirements traceability

Permanent project artifact (introduced at Milestone 3 close). One row
per normative requirement of the Mnemosyne MVP Technical Specification
v1.1; every future milestone MUST update this matrix. No fixed MVP
requirement may silently disappear from the roadmap, and a requirement
is only IMPLEMENTED when delivered and gated — an architectural
placeholder does not count.

Statuses: `IMPLEMENTED` · `PARTIAL` · `PLANNED` · `DEFERRED_WITH_REASON` · `BLOCKED`

## Library & ingestion (Milestone 1)

| Requirement | Spec area | Status | Milestone | Implementation | Tests/gates | Remaining gap |
|---|---|---|---|---|---|---|
| EPUB upload + admin approval workflow | Library intake | IMPLEMENTED | M1 | `SubmissionService`, `SubmissionApiController` | Feature/Api + Library suites | — |
| Bulk filesystem import (read-only, resumable discovery) | Library intake | IMPLEMENTED | M1 | `mnemosyne:library:discover/import` | DiscoverCommandTest | Real bulk library never scanned yet (owner decision) |
| Content-addressed original storage, exact dedup | Storage | IMPLEMENTED | M1 | `LibraryStorage`, `HashStage` | ingestion suites | — |
| Safe EPUB 2/3 parsing (zip-bomb, path traversal, sanitization) | Ingestion safety | IMPLEMENTED | M1 | Python worker stages | worker pytest (zip safety etc.) | — |
| Canonical text + structural artifacts with node offsets/hashes | Source fidelity | IMPLEMENTED | M1 | `canonical.txt`, `spine/*.jsonl`, structure.json | Integration E2E + M2/M3 provenance gates | — |
| Work / Edition / BookAsset model + conservative reconciliation | Domain | IMPLEMENTED | M1 | models + `WorkReconciler` | Library suites | Contributor enrichment (M4) |
| Content-similarity duplicate candidates (admin resolved) | Dedup | IMPLEMENTED | M1 | `DuplicateCandidate` + admin UI | dedup suites | — |
| Processing control plane (pause/resume/retry/priorities/events) | Operations | IMPLEMENTED | M1 | `IngestionRun` state machine, admin processing UI | Ingestion suites ×3 stability | M1 review backlog items (below) |
| Per-user book access grants (ACL foundation) | Security | IMPLEMENTED | M1 | `BookAccessGrant` | ACL tests in every milestone since | Roles beyond admin/user are out of MVP scope |

## Retrieval foundation (Milestone 2)

| Requirement | Spec area | Status | Milestone | Implementation | Tests/gates | Remaining gap |
|---|---|---|---|---|---|---|
| Deterministic structural chunking with exact provenance | Retrieval | IMPLEMENTED | M2 | `Chunker` 1.0.0 + `RetrievalEvidenceSpan` | ChunkerTest, PG schema tests | — |
| Exact / lexical / dense retrieval + weighted RRF hybrid | Retrieval | IMPLEMENTED | M2 | retrievers + `RankFusion` | quality gates (integration ×3) | Recall targets formalized in M7 golden set |
| Local CPU embeddings (multilingual-e5-small, pinned) | AI providers | IMPLEMENTED | M2 | worker `/internal/v1/retrieval` | realmodel pytest + integration | — |
| Reranking capability | Retrieval | IMPLEMENTED | M2 | `WorkerRerankerProvider` (opt-in, off by default after measurement) | RerankerPolicyTest | Latency/quality revisit at M7 |
| Versioned blue/green retrieval generations | Index lifecycle | IMPLEMENTED | M2 | `RetrievalGeneration` manager | generation isolation tests | Superseded-data cleanup intentionally manual |
| Retrieval REST API + admin search debugger | API/Admin | IMPLEMENTED | M2 | `/api/v1/retrieval/search` + `/admin/retrieval` | RetrievalApiTest | — |
| Multilingual search (language-agnostic lexical config + multilingual embeddings) | Multilingual | IMPLEMENTED | M2 | `'simple'` tsvector, e5 | cross-language cases M2/M3 | — |

## Grounded answers + citations + reader (Milestone 3)

| Requirement | Spec area | Status | Milestone | Implementation | Tests/gates | Remaining gap |
|---|---|---|---|---|---|---|
| Query intent classification (versioned) | Answering | IMPLEMENTED | M3 | `QueryIntentClassifier` query-intent 1.0.0 | QueryIntentClassifierTest | Rule-based v1; model-assisted classification may come with M4 signals |
| Dynamic per-intent retrieval policy (versioned, config-driven) | Answering | IMPLEMENTED | M3 | `RetrievalPolicyResolver` retrieval-policy 1.0.0 | policy tests | — |
| Bounded EvidencePacket / EvidenceUnit layer | Answering | IMPLEMENTED | M3 | `EvidencePacketBuilder`, `EvidenceUnitizer` 1.0.0 | unitizer/packet suites (exact invariants) | Budget tuning for stronger future providers |
| Bounded evidence-sufficiency retry (single expansion) | Answering | IMPLEMENTED | M3 | orchestrator `expanding_retrieval` | pipeline tests | Iterative retrieval beyond one expansion is M5 |
| Comparative multi-book per-book coverage | Answering | IMPLEMENTED | M3 | per-book retrieval + book-fair interleave | naive-Top-K-domination regression | Deep comparative analysis is M5 |
| Structured claim generation (no free-form RAG prose) | Answering | IMPLEMENTED | M3 | `GenerationProvider` + validators | generator validation gates | Claim quality bounded by local 8B model |
| Independent per-claim verification (strict entailment + application gate) | Answering | IMPLEMENTED | M3 | `VerifierProvider` (grounded-verifier 1.1.0, sentence atoms) + deterministic `ClaimEvidenceGate` (claim-gate 1.0.0: atomic facts require direct support, strong needs ≥2 independent atoms, association≠identity structural check) | hard-negative gates (association≠identity, mention≠attribute) + rejection/downgrade/conflict gates | Verifier quality bounded by local 8B model; gate is the deterministic backstop |
| Verified citations at CitationSpan precision | Citations | IMPLEMENTED | M3 (corrective) | sentence atoms (E3.S2) persisted per claim↔evidence; reader highlights minimal spans | span precision + Unicode gates | Sentence-level is the smallest reliable deterministic span |
| Compound-question decomposition + honest partial answers | Answering | IMPLEMENTED | M3 (corrective) | `QuestionDecomposer` 1.0.0 (max 4 SQ), per-SQ retrieval + focused single expansion, per-SQ coverage → partially_answered with explicit unanswered parts | compound/partial gates | Bounded; deep multi-hop remains M5 |
| Response language follows the question | UX | IMPLEMENTED | M3 (corrective) | `ResponseLanguageDetector` + generator prompt contract; persisted per run | language tests (it/en/cross-language) | Detector is stopword-heuristic (it/en/es/fr/de) |
| Visible completed duration (persisted backend time) | UX | IMPLEMENTED | M3 (corrective) | `duration_ms` in API/UI ("Completata in …") separated from epistemic labels | UI checks | — |
| Task contracts + question faithfulness (claims must ANSWER the question) | Answering | IMPLEMENTED | M3 (corrective 2) | `TaskContract` 1.0.0 (task types/answer shapes/coverage), `ClaimRelevanceGate` 1.0.0 (shape/entity/relation/location checks + anchor floor; verifier advisory bit reject-only), `TaskCoverageEvaluator` 1.0.0 (outcome from material coverage, never claim counts) | task-adherence gates (grounded-but-irrelevant = 0 displayed) | Deterministic heuristics (it/en); M4 enrichment will strengthen entity grounding |
| Capability gate before generation (global/top-N/longitudinal/reveal) | Honesty | IMPLEMENTED | M3 (corrective 2) | unsupported contracts short-circuit pre-generation with capability notice; mixed questions answer supported parts | short-circuit gates (0 model calls) | Global ranking/longitudinal proof remain M4/M5 |
| Ambiguity clarification (`needs_clarification`) | UX/honesty | IMPLEMENTED | M3 (corrective 2) | `QuestionWellFormednessGate` 1.0.0 (high-confidence dangling-determiner detection), cheap terminal outcome, UI clarification card | wellformedness false-positive suite | Pattern-based; context resolution limited to non-firing pronouns |
| Task-aware retrieval recall (multi-query, relation/state variants, neighborhood) | Retrieval | IMPLEMENTED | M3 (corrective 2) | `QueryReformulator` 1.0.0 (≤4 deterministic variants: normalized/relation-lexicon/state-opposite), bounded local-episode neighborhood (±2 chunks around sibling anchors), focused expansion unchanged | relation/negative-state/neighborhood gates + PG recall set | Heuristic lexicons (it/en); no persistent entity graph (M4) |
| Verifier protocol robustness (claim-local vs systemic) | Robustness | IMPLEMENTED | M3 (corrective 2) | candidate-listing atom repair, claim-local rejection (VERIFIER_INVALID_SUPPORT_ATOM) never kills sibling claims, systemic VERIFIER_PROTOCOL_ERROR when all claims malformed, failed-stage timings persisted (finally) | protocol robustness gates | — |
| Five epistemic outcomes incl. Evidenza insufficiente / Contraddizione | Answering | IMPLEMENTED | M3 | `EpistemicLabel` mapping | epistemic contract tests | — |
| No fake confidence percentages | UX honesty | IMPLEMENTED | M3 | labels only | UI + presenter | — |
| Verified citations: server numbering, durable evidence, exact excerpts | Citations | IMPLEMENTED | M3 | `grounded_answer_evidence` + `CitationAssembler` logic in orchestrator | citation audit gates | — |
| Historical answers independent of active generation | Citations | IMPLEMENTED | M3 | evidence→canonical resolution | supersession regression | — |
| Stale-source fail-closed (`CITATION_SOURCE_CHANGED`) | Citations | IMPLEMENTED | M3 | presenter + `ReaderResolver` double hash check | stale gates | Re-ingest/repair lifecycle tooling (M2 backlog F9) |
| Persistent conversations, follow-ups, scope changes | Conversations | IMPLEMENTED | M3 | Conversation/Message models | conversation ACL/scope tests | Full central conversation UX polish is M6 |
| History is never evidence (no assistant prose reuse) | Epistemics | IMPLEMENTED | M3 | user-questions-only context | context isolation test | — |
| Prompt-injection resistance (book content = data) | Security | IMPLEMENTED | M3 | prompt contract + validators | adversarial fixtures (release gate) + real-provider probe | Ongoing hardening as models change |
| Model world-knowledge isolation | Security | IMPLEMENTED | M3 | packet-only prompting; empty packet short-circuit | world-knowledge gates (det. + real) | LLM discipline is probabilistic; verifier + labels bound it |
| Asynchronous answer execution with real progress | UX | IMPLEMENTED | M3 | answers queue + persisted statuses | API/UI tests | — |
| Answers REST API (202 lifecycle) + OpenAPI | API | IMPLEMENTED | M3 | `/api/v1/answers` + conversations | AnswerApiTest, OpenAPI | MCP adapter is M6 |
| Admin answer inspector (full audit, no CoT) | Admin | IMPLEMENTED | M3 | `/admin/answers` | inspector props tests via presenter | — |
| Evidence Reader v1: exact highlight, section nav, source panel | Reader | IMPLEMENTED | M3 | `ReaderResolver` + reader page | Unicode/multi-node/stale reader gates | Rich EPUB-native reader is M6 (see below) |
| Multilingual answering (cross-language question/source) | Multilingual | IMPLEMENTED | M3 | language-agnostic pipeline | cross-language gate | Broader language QA at M7 |
| Generation provider abstraction (vendor-independent) | AI providers | IMPLEMENTED | M3 | provider interfaces + Ollama adapter + fakes | provider failure suites | Configurable multi-provider ROUTING is M6 |

## Enrichment / pre-understanding (Milestone 4 — PLANNED)

All PLANNED, preserved verbatim from the MVP specification: hierarchical
summaries; entity extraction; alias resolution; events; relationships;
timeline construction; themes; clues; contradictions; **evidence links
for every derived structure** (each derived item must cite its sources
with the same EvidenceUnit discipline built in M3).

## Deep analysis (Milestone 5 — PLANNED)

All PLANNED: analysis planner; entity resolution at query time;
iterative retrieval (M3's single bounded expansion is explicitly NOT a
replacement); structural coverage; temporal coverage; gap search;
counterevidence search; multi-hop reasoning; longitudinal analysis;
deep comparative analysis; persistent/resumable deep jobs;
cancellation; partial-result semantics. Until then
`global_summary`/`longitudinal`/`tricky_inference` intents carry an
explicit capability notice (M3).

## Product completeness (Milestone 6 — PLANNED)

All PLANNED: Rapida / Accurata / Deep answer modes; spoiler-safe
per-book reading limits; hierarchical collections; complete central
conversation UX; richer EPUB-native reader (visual fidelity,
href/fragment/CFI navigation, split answer/reader workflow,
annotations/bookmarks where in final scope); downloadable authorized
derived dossiers; configurable provider/model routing; full REST
surface; user-facing MCP tools with identical ACL (REST remains
canonical — ADR-005).

## Quality & scale (Milestone 7 — PLANNED)

All PLANNED: intentionally difficult 10-book golden set; citation
validity acceptance target; unsupported-factual-claim gate; no-answer
correctness; retrieval recall targets for Accurate/Deep;
longitudinal-coverage quality gate; 100k+ scale benchmarking
(including answer generation and the M2 admin id-list scope
refinement); storage/capacity validation; backup/recovery
verification; operational hardening; security hardening.

## Cross-cutting / unresolved

| Requirement | Status | Notes |
|---|---|---|
| Repository LICENSE decision | PLANNED (unresolved) | Required by the MVP spec before final distribution/MVP completion. The repository being public does NOT define a license. Owner decision — deliberately NOT taken during M3. |
| Milestone 1 review backlog (stale-import regression, promotion fsync, KPI enums, cache-lock TTL, long discovery paths, zero-age reaper, historical candidate pre-dedupe, pause TOCTOU, grant upsert concurrency) | DEFERRED_WITH_REASON | Non-blocking hardening; tracked since M1 close. |
| Milestone 2 review backlog (F1 OpenAPI reranker timeout enum, F2 reranker score metadata honesty, F3/F4 exact boundary/config coupling, F5/F6 exact Unicode recall-only divergences, F7 lexical fallback API transparency, F8 concurrent activation loser handling, F9 source hash reset/re-ingestion lifecycle, F10 building-generation incremental enqueue hole, LOW findings) | DEFERRED_WITH_REASON | Non-blocking per independent M2 review; F9 intersects M3 stale-citation lifecycle and rises in priority with re-ingestion tooling. |
| M3 chunk/excerpt offset wording ambiguity (M2 finding) | IMPLEMENTED | M3 citations use durable canonical answer-evidence coordinates, eliminating the ambiguity for the answer path. |
