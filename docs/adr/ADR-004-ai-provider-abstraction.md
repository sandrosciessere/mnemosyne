# ADR-004 — AI provider abstraction

**Status**: accepted (2026-08-10)

## Context

Mnemosyne will mix local models (embeddings, rerankers), host Ollama and
external APIs (OpenAI-compatible, Claude, Codex), on a CPU-only host
(AVX2, no GPU). Models will change over time and must be benchmarked.

## Decision

Every AI capability is resolved through a named provider in one of five
categories — embeddings, reranker, generation, verifier, deep-analysis
(`config/mnemosyne.php`). Domain code never references a concrete
provider. Ollama is an optional dependency reached via
`host.docker.internal` and never affects service readiness. Host
Claude/Codex CLIs are out of bounds until dedicated bridges exist.

## Consequences

- Models and providers are swappable and benchmarkable per category.
- Embeddings are versioned (model + dimensions); schema must not hardcode
  either; blue/green index rebuilds are required for upgrades.
- Slightly more indirection up front, paid once.
