# ADR-001 — Monorepo

**Status**: accepted (2026-08-10)

## Context

Mnemosyne spans a Laravel application, a Python AI worker, a future MCP
adapter, container definitions and documentation, developed largely by
coding agents that benefit from seeing the whole system at once.

## Decision

One repository (`sandrosciessere/mnemosyne`) containing `apps/`,
`services/`, `docker/`, `docs/` and the Compose stack.

## Consequences

- Atomic cross-service changes and one place for agent guidance (AGENTS.md).
- CI (future) must scope jobs per subtree to stay fast.
- Repository size must stay code-only: data, models and EPUBs are banned.
