# Runbook

All commands from `/srv/projects/mnemosyne` as the `mnemosyne` user.

| Action | Command |
|---|---|
| Build images | `make build` |
| Start stack | `make up` |
| Stop stack | `make down` |
| Status | `make ps` |
| Logs (follow) | `make logs` |
| Health smoke test | `make health` |
| Run migrations | `make migrate` (explicit — never automatic on start) |
| PHP + Python tests | `make test` |
| Lint all | `make lint` |
| App shell | `make shell` |
| Worker shell | `make worker-shell` |
| Arbitrary artisan | `make artisan CMD="route:list"` |
| Horizon status | `make artisan CMD="horizon:status"` |
| Create first admin | `docker compose exec app php artisan mnemosyne:user:create-admin` |
| Integration + E2E suite | `make test-integration` (then `make test-integration-down`) |
| Filesystem discovery (read-only scan) | `make artisan CMD="mnemosyne:library:discover --source=<name>"` (`--dry-run`, `--limit`, `--resume=<run>`) |
| Import a discovery manifest | `make artisan CMD="mnemosyne:library:import <run> --priority=low"` (restart-safe; `--limit`, `--retry-failed`) |
| Reap ingestion quarantine leftovers | `make artisan CMD="mnemosyne:ingestion:cleanup"` (dry-run; add `--force` to delete, `--min-age-hours=N`) |
| Stale-run check (manual) | `make artisan CMD="mnemosyne:ingestion:detect-stale --dry-run"` |
| Pipeline smoke test | `make artisan CMD="mnemosyne:ingestion:selftest"` |

## Health endpoints

- `GET /health/live` — app liveness (no dependencies)
- `GET /health/ready` — checks PostgreSQL, Redis, `/data` (503 on failure)
- `GET /api/v1/health` — API liveness
- Worker (internal only): `GET /health/live`, `GET /health/ready`
  (reports `ollama: available|unavailable` separately; Ollama never fails
  readiness)

## Horizon

Dashboard at `/horizon`, restricted to authenticated admins (403
otherwise). Queue worker runs in the `horizon` container; scheduler in the
`scheduler` container (`schedule:work`).

Queue/resource strategy (shared host, evolutive for M4/M5 batch jobs):
`supervisor-answers` (queue `answers`, concurrency 1, nice 5) runs the
interactive grounded-answer pipeline — one job at a time because the
local generation model is CPU-serial, at BETTER priority than
`supervisor-retrieval` (nice 10) so bulk indexing/embedding can never
starve interactive answers; `supervisor-ingestion` handles book intake.
Per-user protection: answers API throttle (default 6/min) + active-run
cap (default 2, `MNEMOSYNE_ANSWER_MAX_ACTIVE_PER_USER`). A stuck answer
cannot wedge: job timeout (default 1500 s) + failed-job reconciliation
move it to `failed` with an error code (`/admin/answers` shows it).

## EPUB ingestion operations

- Queues: `ingestion-high` → `ingestion-normal` → `ingestion-low`
  (strict priority, `supervisor-ingestion`); concurrency via
  `MNEMOSYNE_INGESTION_CONCURRENCY` (default 2, conservative on purpose —
  raising it needs a `.env` change + `make up`).
- Control plane: `/admin/processing` (dashboard, runs list, run detail
  with retry/cancel/priority/override), `/admin/submissions` (approvals),
  `/admin/system` (auto-approval toggle, limits, pipeline versions),
  `/admin/library` (Work → Edition → Asset navigation).
- Scheduler now has a real healthcheck (`mnemosyne:scheduler:healthcheck`
  driven by a heartbeat schedule) and runs
  `mnemosyne:ingestion:detect-stale` every 5 minutes (threshold
  `MNEMOSYNE_INGESTION_STALE_MINUTES`, default 30).
- Pause: per-run (run detail page) and global (`/admin/processing`
  banner/button, or `POST /api/v1/admin/processing/pause|resume`).
  Cooperative: running stages finish safely, nothing new starts; state
  is persisted (`ingestion_paused` setting) and survives restarts.
- Bulk import is two phases: `discover` (read-only, resumable manifest)
  then `import <run>` (creates submissions; restart-safe). Approve via
  the admin UI or enable auto-approval before importing large batches.
  - Non-UTF-8 filenames are safe: the manifest stores paths byte-exact
    (base64 of the raw bytes) with a separate valid-UTF-8 `display_path`
    for the UI/logs — latin-1 names are neither mangled nor dropped.
  - `--resume` refuses if the source root changed since the run; a
    `--dry-run --resume` previews without mutating the run.
  - `import` fails an entry **retryably** if free disk is under
    `MNEMOSYNE_MIN_FREE_DISK_BYTES` (stops the run instead of spinning);
    re-run with `--retry-failed` once space is back. `--retry-failed`
    re-queues transient failures **only** — symlink/containment
    (`SECURITY:`-tagged) failures are never auto-retried.
- `mnemosyne:ingestion:cleanup` reaps quarantine leftovers that a crash,
  ENOSPC, or a lost claim can leave behind. It is **dry-run by default**
  (reports only); pass `--force` to delete and `--min-age-hours=N` (default
  1) to bound freshness. It deletes ONLY: (1) `library/incoming/{ulid}`
  dirs no live submission still points into, and (2) stale `.tmp-*` files
  under `library/original/**` (interrupted promotions). It NEVER touches an
  immutable original, a live submission's incoming dir, or anything outside
  those two trees. Run it when imports are idle.
- New `.env` keys (see `.env.example`): `MNEMOSYNE_INTERNAL_TOKEN`
  (worker auth — generate with `openssl rand -hex 32`),
  `MNEMOSYNE_MAX_UPLOAD_BYTES`, `MNEMOSYNE_INGESTION_CONCURRENCY`,
  `MNEMOSYNE_IMPORT_SOURCES` (allowlist for `mnemosyne:library:discover`),
  worker limits `WORKER_MAX_*`.
- Upload size: the application limit (`MNEMOSYNE_MAX_UPLOAD_BYTES`,
  default 150 MB) must stay ≤ PHP `upload_max_filesize`/`post_max_size`
  and nginx `client_max_body_size` — the container images already ship
  1G for all three. The HOST nginx vhost must also allow the size; if
  large uploads fail with 413, the administrator needs to raise
  `client_max_body_size` there (root action).
- Data safety: never touch `library/original` by hand; artifacts under
  `library/extracted` are regenerable; incoming files are quarantine.

## Deploy after a code change

```bash
git pull
make build && make up      # recreates changed containers
make migrate               # if new migrations
make health
```

## Rebuilding from scratch (safe)

`docker compose down` keeps volumes. Never use `docker compose down -v`
casually — it destroys the database volumes. Never run global `docker
* prune` commands on this shared host.

## TLS / vhost

Host nginx vhost: `/etc/nginx/sites-enabled/mnemosyne.conf` proxying to
`127.0.0.1:8100`; certificates via host Certbot. Test config with
`nginx -t` and use `systemctl reload nginx` (never restart casually).
