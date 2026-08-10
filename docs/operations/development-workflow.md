# Development workflow

## Starting a session

```bash
sudo -iu mnemosyne          # run by the ADMINISTRATOR, before launching the agent
cd /srv/projects/mnemosyne
make preflight              # must end with READY FOR DEVELOPMENT
```

The coding agent runs entirely as `mnemosyne` and never uses `sudo`.

## Starting a feature

```bash
git fetch origin
git checkout main
git pull --ff-only
git checkout -b feat/example      # feat/ fix/ refactor/ chore/
```

Significant domain work always happens on a feature branch, never
directly on `main`. `main` must stay buildable, deployable and tested.

## Before commit

```bash
make lint                   # check-only: pint --test, eslint, prettier --check, ruff check
make test                   # PHP suite (host, sqlite) + Python suite
cd apps/web && npm run build
```

Autofix commands are explicit and separate: `make lint-ts-fix`,
`make format-php`. `make lint` never modifies files.

## Before merge

- branch up to date with `main`; merge fast-forward only, no force push
- tests, lint and frontend build green; working tree clean
- staged diff reviewed: no secrets (.env, PAT, APP_KEY, passwords, keys)

## Root boundary

Operations that require root are **not** part of development sessions.
The agent stops and hands the administrator the exact command for:

- `/etc/nginx` (vhosts) and Certbot/TLS
- firewall, host systemd units, Debian packages
- Docker daemon configuration
- filesystem/ownership outside `/srv/projects/mnemosyne`,
  `/srv/data/mnemosyne` and `/home/mnemosyne`

## Permissions (reference, do not "optimize")

- repository root: `mnemosyne:mnemosyne` 0775 — acceptable: the repo is
  public code and must never contain secrets
- `.env` (untracked): `mnemosyne` 0600
- `/srv/data/mnemosyne`: `mnemosyne:mnemosyne` 0750
- `~/.config/git/credentials`: `mnemosyne` 0600 (never read or print it)

## Known development warnings

- **Starlette/httpx TestClient deprecation** in the Python suite: not a
  blocker; will be revisited when the AI worker gains its real
  dependencies. Do not bump versions just for this.
- **Scheduler health monitoring is deferred** until scheduled domain tasks
  exist (ingestion watchdog/recovery, cleanup, metric aggregation). No
  artificial healthcheck before that.
