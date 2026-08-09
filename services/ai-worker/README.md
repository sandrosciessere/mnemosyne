# Mnemosyne AI worker

Containerized Python 3.12 service that will host the document/AI pipelines
(EPUB parsing, chunking, embeddings, summarization, entity extraction…).

**Current status: bootstrap skeleton.** It only exposes:

- `GET /health/live` — process liveness
- `GET /health/ready` — checks PostgreSQL, Redis and the `/data` mount;
  reports host Ollama reachability separately as an *optional* dependency
  (`"ollama": "available" | "unavailable"`), which never affects readiness.

No ML dependencies (PyTorch, Transformers, models) are installed yet — they
will be introduced together with the provider abstraction in later sessions.

## Tests / lint

From the repository root:

```bash
make test-python
make lint-python
```
