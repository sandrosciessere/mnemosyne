# ADR-005 — REST canonical API, MCP as adapter

**Status**: accepted (2026-08-10)

## Context

Mnemosyne will be consumed by its own web UI, by API clients and by AI
agents via MCP.

## Decision

The versioned REST API (`/api/v1/...`, Sanctum-compatible) is the single
canonical application interface. The future MCP server
(`services/mcp`) is an adapter over the REST API, limited to library/query
tools (`search_library`, `ask_books`, `deep_analyze`, `get_book`,
`get_source`, `list_collections`, `submit_epub`) and never exposes
administrative functions.

## Consequences

- One implementation of business rules, auth and ACLs.
- MCP capabilities can lag the API without architectural drift.
- API design must consider agent consumers from the start.
