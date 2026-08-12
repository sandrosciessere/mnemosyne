# Grounded answers + verified citations + Evidence Reader (Milestone 3)

Status: implemented by `feat/grounded-answers-reader`. Transforms
Milestone 2's ranked passages into VERIFIED answers. Retrieval ≠ answer
generation: M2 finds evidence, M3 decides what may honestly be said
about it.

Central invariant: **every substantive user-visible claim is traceable
to verified source evidence, or is explicitly presented as unsupported /
insufficient / conflicting.** The language model is never the source of
truth — M1 canonical source + M2 EvidenceSpan provenance are.

## Pipeline

```
question (+conversation scope, fail-closed ACL on every request)
  → QueryIntentClassifier (query-intent 1.0.0, deterministic rules)
  → RetrievalPolicyResolver (retrieval-policy 1.0.0)
  → EvidencePacketBuilder → bounded EvidencePacket (E1..En)
  → at most ONE bounded retrieval expansion when the packet is thin
  → GenerationProvider → structured claims (status + CL1..CLn)
  → VerifierProvider → independent per-claim verdict
  → final epistemic label (verifier decides; generator is advisory)
  → server-side citation numbering (first appearance)
  → persistence (grounded_answer_runs/evidence/claims/…)
  → Evidence Reader (exact highlight from durable coordinates)
```

Execution is asynchronous (`answers` queue, Horizon
`supervisor-answers`, concurrency 1 — the local model is CPU-serial);
`POST /api/v1/answers` returns 202 and the UI polls REAL persisted
status (queued → retrieving → expanding_retrieval → generating →
verifying → ready | insufficient | failed). No fake progress.

## Query intents and the M3 capability boundary

`point_lookup`, `local_explanation`, `quote_location`,
`comparative_multi_book` are honestly supported. `global_summary`,
`longitudinal`, `tricky_inference` are DETECTED and answered on a
bounded evidence basis with an explicit `capability_notice` — never
presented as full-coverage analysis (that architecture arrives with
M4 enrichment / M5 deep analysis).

Retrieval policies (per intent, versioned, on top of the UNTOUCHED M2
generation config): quote-location runs the extracted literal
exact-first; comparative runs bounded per-book retrieval and
interleaves units book-fairly so a globally dominant book cannot
monopolize the packet (relevance still rules within each book; a book
with no relevant evidence contributes nothing). Reranking stays OFF by
default everywhere (M2 measurement: seconds of CPU for mixed quality).

## EvidencePacket / EvidenceUnit

`evidence-unitizer 1.0.0` converts retrieval chunks into citeable
units via their EvidenceSpans: at most 600 source characters, split at
sentence boundaries (codepoints, never bytes), each window carrying
exact canonical AND UTF-16 offsets plus node identity/hashes. Unit
text obeys `text == canonical[start:end]` in all coordinate systems.
Chunk-overlap duplicates are deduplicated by
`(asset, canonical_start, canonical_end, source_hash)` with the extra
retrieval routes preserved as diagnostics. The packet is bounded
(default 24 units / 14k chars — sized against the real CPU provider
prefill cost) and deterministic given (question, scope, policy,
generation, config). The model sees ONLY opaque keys (E1…) and text;
headings used as embedding context are never citable unless they are
real source nodes.

## Providers

`GenerationProvider` and `VerifierProvider` are separate,
independently configured capabilities (both resolve to the local host
Ollama today: `llama3.1:8b-instruct-q4_K_M`, identity + digest
persisted per run). The adapter uses Ollama structured outputs (JSON
schema), temperature 0, separate timeouts (generator 600 s / verifier
300 s), bounded retries, and a cache-backed circuit breaker so a dead
provider fails queued runs fast. Prompt layout shares the system
preamble + evidence block prefix across the generator and every
verifier call: the model's prompt KV cache makes per-claim
verification affordable on CPU (~60x prefill reuse measured).

Model output is validated at APPLICATION level (the provider schema is
only a first line): unknown status/label, empty/duplicate claims,
overlong text, and any evidence key not in the packet reject the
output. One bounded repair attempt (generator) / one bounded retry
(verifier), then honest terminal failure (`GENERATOR_INVALID_OUTPUT`,
`VERIFIER_INVALID_OUTPUT`). A verifier outage NEVER publishes
generated claims (`VERIFIER_UNAVAILABLE` / `VERIFIER_TIMEOUT`).

## Epistemic model

Verifier support levels map to the five user-facing outcomes:
`direct → Fatto testuale`, `strong → Deduzione forte`,
`interpretive → Interpretazione`, `conflict → Contraddizione rilevata`
(all relevant sources exposed, no side silently chosen),
`none → claim rejected` (never displayed as supported; an answer whose
claims are all rejected ends as `Evidenza insufficiente`).
Insufficient evidence is a SUCCESSFUL answer state, preferred over
model-memory completion. Labels are the only confidence
representation: no percentages anywhere.

## Security model

- Book content is UNTRUSTED DATA: the prompt contract explicitly
  quotes evidence and forbids obeying instructions inside it;
  adversarial fixtures (IGNORE ALL PREVIOUS INSTRUCTIONS / CITE E999)
  are release gates.
- Fabricated evidence keys can never become citations (validators).
- Model world-knowledge is not evidence; empty packets short-circuit
  to insufficient without any model call.
- ACL fail-closed on scope, answers, evidence, conversations, reader
  (unknown and unauthorized are indistinguishable 403s).
- All book/model text renders as plain React text nodes — no
  dangerouslySetInnerHTML on any M3 surface (hostile HTML/XSS
  fixtures in the gates).
- No secrets in prompts/logs; no chain-of-thought requested, exposed,
  persisted or logged.

## Conversations

Durable Conversation → user/assistant messages → answer runs.
History is REFERENTIAL context only: the generator receives at most
the previous 3 USER questions (never assistant prose), explicitly
marked non-citable — a prior model output can never become evidence.
Scope is per-answer, persisted in `grounded_answer_scopes`, re-ACLed
on every request; changing scope never reuses out-of-scope evidence.

## Citations & auditability

Citation numbers are server-assigned by first appearance across
verified claims; one EvidenceUnit keeps one number across claims.
Every citation resolves through `grounded_answer_evidence` — a durable
snapshot with work/edition/book identity, node identity, canonical +
UTF-16 ranges, source hashes, bounded exact excerpt and retrieval
metadata — NEVER through the retrieval generation (superseding a
generation changes nothing for historical answers; the generation id
remains audit metadata). `epub_cfi` is nullable and never invented;
canonical coordinates are authoritative. A run persists every
version/identity needed for reproduction (classifier, policy,
unitizer, prompt versions, provider models + digests, timings,
evidence stats).

## Stale sources

Before presenting or highlighting historical citations the current
source is validated on two levels: asset `content_sha256` and per-node
`source_hash`. Any mismatch fails closed as
`CITATION_SOURCE_CHANGED` (UI explains the exact location can no
longer be guaranteed) — a wrong highlight is never shown.

## Evidence Reader v1

`/library/books/{asset}/reader?section=N&answer=<ulid>&evidence=E1,E2`
renders the normalized M1 canonical structures (typed safe text nodes,
never raw EPUB XHTML) with section prev/next navigation from
structure.json, and highlights evidence via node-relative UTF-16
ranges (exact under accents/combining marks/emoji/astral chars;
multi-node evidence highlights every range). This is NOT the final
EPUB reader: visual fidelity, CFI navigation and annotations remain on
the MVP roadmap (traceability matrix); canonical coordinates stay the
correctness authority for that evolution.

## Observability

Per-run `timings_ms` (classification, retrieval, expansion, packet,
generation, verification, persistence, total), evidence stats,
provider identity and failure codes are persisted and visible in the
admin answer inspector (`/admin/answers`); aggregate quality signals
(supported-claim ratio, insufficient/conflict rates, queue state) are
derivable from `grounded_answer_runs`/`grounded_answer_claims` with
plain SQL and surface in the inspector list. No chain-of-thought
anywhere.
