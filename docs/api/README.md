# API

The versioned REST API under `/api/v1/` is the canonical application
interface (see ADR-005). Sanctum-compatible authentication; no permanent
tokens are issued at this stage.

Current endpoints:

| Method | Path | Auth | Description |
|---|---|---|---|
| GET | `/api/v1/health` | none | API liveness |
| GET | `/api/v1/user` | `auth:sanctum` | Current user |

Library, search and analysis endpoints arrive with the domain sessions.
OpenAPI documentation generation is intentionally deferred.
