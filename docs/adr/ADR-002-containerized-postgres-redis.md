# ADR-002 — Containerized PostgreSQL and Redis

**Status**: accepted (2026-08-10)

## Context

The shared host runs a PostgreSQL 17 instance (127.0.0.1:5433) used by
other projects. Mnemosyne needs pgvector, aggressive future tuning, and
clean portability to dedicated hardware.

## Decision

Mnemosyne runs its own PostgreSQL 17 + pgvector and Redis 7 containers
inside the `mnemosyne` Compose project, on the internal `backend` network,
with no host-published ports.

## Consequences

- Full isolation from other projects; extension/tuning lifecycle is ours.
- Reproducible deployment and simpler backup/restore/migration.
- We own upgrades and backups (host tooling won't cover these databases).
