# ADR-006 — PostgreSQL + pgvector as the vector store

**Status**: accepted (2026-08-10)

## Context

The retrieval engine needs vector search alongside relational data,
full-text search and the ingestion state machine. Dedicated vector
databases (Qdrant, Weaviate, Milvus) and search engines (Elasticsearch)
would add operational components on a shared host.

## Decision

PostgreSQL 17 with the `vector` extension (pgvector image
`pgvector/pgvector:pg17`) is the single store for relational data,
full-text and vectors in the initial phase. The extension is created by an
idempotent migration (`CREATE EXTENSION IF NOT EXISTS vector`).

## Consequences

- One database to operate, back up and tune; transactional consistency
  between domain data and vectors.
- Hybrid retrieval (lexical + vector) stays in one engine.
- If benchmarks at scale demand it, a dedicated vector store can be
  reconsidered — behind the provider/repository boundary, not ad hoc.
