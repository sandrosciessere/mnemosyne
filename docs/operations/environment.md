# Environment

## Host

- `shde-ed837.serverlet.com` — Debian 13 (trixie), shared with other
  projects (never touch them; see AGENTS.md).
- CPU: Intel Xeon E5-2689 v4 — 10 cores / 20 threads, **AVX2, no AVX-512**,
  **no GPU** (all local AI is CPU-only; choose AVX2-friendly builds).
- RAM: ~62 GB. Storage: 2× NVMe RAID1 (`/dev/md3`, ext4, ~3.5 TB total).
- Docker 29 + Compose v5.

## Mnemosyne footprint

| Resource | Value |
|---|---|
| Repository | `/srv/projects/mnemosyne` (owner `mnemosyne:mnemosyne`) |
| Data tree | `/srv/data/mnemosyne` → `/data` in containers |
| Compose project | `mnemosyne` (networks `mnemosyne_edge`, `mnemosyne_backend`) |
| Host port | `127.0.0.1:8100` (web ingress only; configurable via `MNEMOSYNE_HTTP_PORT`) |
| Public URL | `https://mnemosyne.shellrent.com` via host nginx |

Container resource limits (initial, conservative — revisit after
benchmarks): pg 4 CPU/8 GB, redis 1 CPU/1 GB, app 2 CPU/2 GB, horizon
2 CPU/2 GB, ai-worker 4 CPU/6 GB, web 0.5 CPU/256 MB, scheduler
0.5 CPU/512 MB. No aggressive PostgreSQL tuning yet.

## Host dependencies

- **Ollama** on `127.0.0.1:11434` (host service, optional dependency),
  reached from containers as `http://host.docker.internal:11434`
  (`host-gateway`). Never manage it from this project.
- Host PostgreSQL on `127.0.0.1:5433` belongs to other projects — never
  used by Mnemosyne.

## Ports already taken on the host

22, 80, 443, 5433, 8080, 8090, 8091, 10050, 11434 — plus 8100 (Mnemosyne).
Any new port must be re-verified with `ss -lntp` and bound to loopback.
