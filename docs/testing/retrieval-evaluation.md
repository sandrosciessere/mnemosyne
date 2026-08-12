# Retrieval evaluation (development benchmark)

This is the DEVELOPMENT benchmark — deliberately small, synthetic and
deterministic. It is **not** the future golden set (10 hand-annotated
real books with graded judgments), which arrives with the quality
program in a later milestone.

## Dataset

`tests/retrieval/evaluation-cases.json` (versioned): 12 cases over a
3-book synthetic corpus (built by `Tests\Support\BuildsEvaluationCorpus`)
covering literal phrases (EN/IT/Greek+CJK/emoji), keyword queries,
paraphrases whose wording differs from the source, adversarial decoys
sharing query vocabulary in irrelevant contexts, an oversized-node split
book, a phrase at a chunk boundary and a must-not-match case. No
copyrighted text anywhere.

## Metrics

Per mode (`exact`, `lexical`, `dense`, `hybrid`, `hybrid+rerank`):
Recall@K (K=10) and MRR, judged by "result chunk contains the expected
phrase in the expected book". Exact-absent cases count false positives.
nDCG is deliberately deferred until graded judgments exist — no
fabricated percentages.

## Running

- CI-grade: `make test-integration` includes `RetrievalQualityTest`,
  which indexes the corpus with the REAL models and enforces the gates
  (exact recall 1.0 + zero false positives + boundary case; paraphrase
  cases found by dense in both languages; hybrid ≥ each component;
  reranker MRR within 0.1 of hybrid; adversarial decoy beaten).
- Manually / comparing generations:
  `mnemosyne:retrieval:evaluate --generation=<ulid>` (book_refs resolve
  automatically when the synthetic corpus is indexed, or pass
  `--book-map=A=<ulid>,…`). Run against generation A and generation B to
  compare — no test-code change needed.

Determinism: exact/lexical are fully deterministic; dense/rerank use
fixed pinned models with normalized embeddings — repeated runs are
stable (verified across the 3× integration repetitions).
