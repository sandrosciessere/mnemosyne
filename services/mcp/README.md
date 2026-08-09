# Mnemosyne MCP adapter (future)

**Not implemented yet.** This directory reserves the place for the future
MCP (Model Context Protocol) server.

Design constraints already decided:

- The **REST API (`/api/v1/...`) is and will remain the canonical
  application interface**. The MCP server is an *adapter* on top of it,
  never a parallel implementation of business logic.
- MCP will expose **read/query library functions only** — no administrative
  functions (user management, ingestion control, system configuration).

Planned tools:

| Tool | Purpose |
|---|---|
| `search_library` | Search across the authorized library |
| `ask_books` | Grounded question answering over one or more books |
| `deep_analyze` | Long-running deep analysis with progress reporting |
| `get_book` | Book/work/edition metadata |
| `get_source` | Retrieve a cited source passage for verification |
| `list_collections` | List collections visible to the caller |
| `submit_epub` | Propose an EPUB for admin approval |
