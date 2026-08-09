# AI provider boundaries

## Principle

Every AI capability is consumed through a **named provider per category**;
the domain never imports a concrete provider. Categories (see
`apps/web/config/mnemosyne.php`):

- `embeddings`
- `reranker`
- `generation`
- `verifier`
- `deep-analysis`

Planned provider families: local Python models (in `ai-worker`), host
Ollama, OpenAI-compatible APIs, Claude (headless/API), Codex
(headless/API), and others. Embedding profiles will be selectable
(`Fast` / `Quality` / `Maximum Quality`; e.g. Qwen3-Embedding-4B is a
candidate for the quality tier — to be benchmarked, not downloaded yet).

## Host Ollama

Reached from containers via `http://host.docker.internal:11434`
(`host-gateway`). It is an **optional dependency**: services must degrade
gracefully and report `ollama: unavailable` without failing readiness.
Never manage host Ollama (models, config, restarts) from this project.

## Claude Code / Codex on the host

The host has Claude Code and Codex CLIs installed. They are **not**
providers of this application at this stage: no container mounts their
binaries or home directories, and their credentials are off-limits.
Future integration will go through dedicated bridges with provider
interfaces, timeouts, queues, concurrency limits and auditing — built for
Mnemosyne, **not** reusing the Webforge bridges.

## Database consequences

Vector schemas must not hardcode one embedding model or dimension.
Embeddings are versioned per model+config; blue/green index rebuilds keep
V1 serving while V2 builds, then switch atomically.
