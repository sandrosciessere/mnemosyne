# CLAUDE.md

**Read and follow [AGENTS.md](AGENTS.md) before making changes.**

## Who you run as

- Normal development sessions run **directly as the Unix user `mnemosyne`**
  (the administrator starts them with `sudo -iu mnemosyne`).
- **First check: `whoami`.** If it is not `mnemosyne`, stop — a normal
  coding task must not proceed as another user.
- When you are `mnemosyne`, **never use `sudo`** and never attempt
  privilege escalation. If a step genuinely needs root (host nginx,
  Certbot, firewall, host systemd, Debian packages, Docker daemon config,
  ownership outside the Mnemosyne areas), stop and hand the administrator
  the exact command to run.

## Session start

```bash
cd /srv/projects/mnemosyne
make preflight        # must end with READY FOR DEVELOPMENT
```

## Hard rules

- Never touch other projects on this shared server (see AGENTS.md).
- Before pushing: `make lint` and `make test` must pass, and inspect
  `git diff` / staged changes for secrets.
