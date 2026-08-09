# Storage layout

## Persistent data tree (host)

`/srv/data/mnemosyne` — owner `mnemosyne:mnemosyne`, mode 0750, bind-
mounted at `/data` in app/horizon/scheduler/ai-worker containers. All
container processes writing here run as uid/gid 1003 (= host `mnemosyne`).

```
/srv/data/mnemosyne/
├── library/
│   ├── incoming/    # uploads/imports awaiting ingestion
│   ├── original/    # immutable original EPUBs (dedup by hash)
│   ├── extracted/   # parsed/normalized content
│   └── exports/     # generated exports
├── models/          # local model weights (never in Git)
├── cache/           # rebuildable caches (HF cache etc.)
├── tmp/             # scratch space
└── backups/tmp/     # staging area before backup offload
```

Future bulk import must also accept external `Author/Title/file.epub`
trees at the scale of hundreds of thousands of files.

## Docker volumes

- `mnemosyne_pg-data` — PostgreSQL data
- `mnemosyne_redis-data` — Redis AOF persistence

Never touch volumes of other projects. `docker compose down -v` destroys
the databases — only with explicit owner approval.

## Capacity notes

Single ext4 filesystem (~3.1 TB free at bootstrap). At target scale
(hundreds of thousands of EPUBs + extracted text + embeddings) budget for
multi-terabyte growth; monitor `df -h` and revisit ADR-003 before mass
ingestion.
