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
