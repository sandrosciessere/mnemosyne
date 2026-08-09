# CLAUDE.md

**Read and follow [AGENTS.md](AGENTS.md) before making changes.**

Claude-specific notes:

- Run all Git/file operations as the `mnemosyne` Unix user
  (`sudo -u mnemosyne -H …`), never as root inside the repository.
- This is a shared production host: respect the safety rules in AGENTS.md
  strictly; when a step would touch anything outside
  `/srv/projects/mnemosyne`, `/srv/data/mnemosyne` or the `mnemosyne_*`
  Docker resources, stop and report instead.
- Before pushing: `make test` and `make lint` must pass, and inspect
  `git diff --cached` for secrets.
