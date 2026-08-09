# ADR-003 — Local persistent library storage

**Status**: accepted (2026-08-10)

## Context

The library will grow to hundreds of thousands of EPUBs plus extracted
content, models and caches. The host has a single large ext4 filesystem
(RAID1 NVMe, ~3.5 TB).

## Decision

Persistent data lives in `/srv/data/mnemosyne` (owner
`mnemosyne:mnemosyne`, 0750), outside the Git checkout, bind-mounted at
`/data`: `library/{incoming,original,extracted,exports}`, `models/`,
`cache/`, `tmp/`, `backups/tmp`. Original EPUBs are logically immutable
once ingested. PostgreSQL/Redis use named Docker volumes instead.

## Consequences

- Code and data lifecycles are decoupled; the repo stays small.
- A future move to dedicated storage is a bind-mount change.
- Disk budget must be monitored: at target scale the data tree plus the
  vector database can exceed a terabyte.
