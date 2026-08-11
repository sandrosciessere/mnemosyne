# ADR-008 — Laravel-owned ingestion orchestration

**Status**: accepted (2026-08-10)

## Context

The ingestion pipeline spans Laravel (domain, queues, admin plane) and
the Python worker (EPUB parsing). Two writers of pipeline state would
make idempotency, auditing and recovery unreliable.

## Decision

Laravel is the single source of truth and the only writer of domain
state (submissions, assets, runs, attempts, events). Horizon executes
one queue job per stage on priority queues (`ingestion-high|normal|low`,
strict order, configurable concurrency). The Python worker exposes a
versioned internal API (`/internal/v1/epub/*`, shared-token auth,
relative paths only) that transforms content and reports a verdict
envelope; it never writes domain tables and never decides transitions.
Every stage execution is recorded as an attempt with handler version;
every transition appends an event.

## Consequences

- One state machine to reason about; retries resume from the blocked
  stage reusing artifacts; cancellation is cooperative at stage
  boundaries; stale runs are detected by heartbeat.
- The worker stays stateless and horizontally replaceable (a future
  dedicated parsing host only needs the data mount and the token).
- Each stage costs one HTTP round-trip — negligible against parse times,
  and it buys durable checkpoints.
