# Mnemosyne AI worker

Containerized Python 3.12 FastAPI service hosting the document pipelines.
Current scope: **safe, deterministic EPUB validation / parsing /
normalization / structure extraction** behind an internal versioned HTTP
API. Every EPUB is treated as untrusted input.

## Endpoints

Health (public, unchanged):

- `GET /health/live` — process liveness
- `GET /health/ready` — checks PostgreSQL, Redis and the `/data` mount;
  reports host Ollama reachability separately as an *optional* dependency
  (`"ollama": "available" | "unavailable"`), never affecting readiness.

Internal EPUB API (`POST`, JSON, prefix `/internal/v1`, all requests must
send `X-Mnemosyne-Internal-Token`; interactive docs are disabled):

| Endpoint | Body | Result |
|---|---|---|
| `/epub/validate` | `{asset_ref, relative_path, correlation_id?}` | zip safety report, container/mimetype/encryption checks, OPF quick parse (version, spine/manifest counts) |
| `/epub/parse` | `{asset_ref, relative_path, artifact_dir, pipeline_version, source_sha256, correlation_id?}` | full OPF metadata; writes `metadata.json`; returns normalized metadata inline |
| `/epub/normalize` | same as parse | writes `spine/NNNN.jsonl` node artifacts; returns counts |
| `/epub/structure` | same as parse | reads the spine JSONL (content docs are **not** re-parsed), writes `structure.json`, returns fingerprint + TOC/spine summary |

All paths (`relative_path`, `artifact_dir`) are relative to
`WORKER_DATA_PATH`; absolute paths, backslashes, `..` segments and
symlink escapes are rejected with `422`. Missing/wrong token → `401`;
token not configured in the environment → `503` (fail closed).

## Response envelope

Every stage responds with:

```json
{
  "status": "passed | passed_with_warnings | needs_review | failed",
  "stage": "validate | parse | normalize | structure",
  "handler_version": "1.0.0",
  "duration_ms": 12,
  "issues": [
    {"code": "…", "severity": "hard_block|reviewable|warning",
     "message": "…", "overrideable": false, "details": {}}
  ],
  "result": {}
}
```

Status derivation: any `hard_block` → `failed`; else any `reviewable` →
`needs_review`; else any `warning` → `passed_with_warnings`; else
`passed`. `hard_block` issues are never overrideable. Unexpected errors
return HTTP 500 with the same envelope (`INTERNAL_ERROR`, generic
message; the traceback is only logged, with the correlation id).

Handler versions (`app/versions.py`): `VALIDATOR_VERSION`,
`PARSER_VERSION`, `NORMALIZER_VERSION`, `STRUCTURER_VERSION`, all
`1.0.0`.

## Issue codes

| Code | Severity | Overrideable | Meaning |
|---|---|---|---|
| `ZIP_INVALID` | hard_block | no | file is not a zip archive |
| `EPUB_FILE_NOT_FOUND` | hard_block | no | source file missing/unreadable |
| `EPUB_FILE_TOO_LARGE` | hard_block | no | compressed file exceeds cap |
| `ZIP_PATH_TRAVERSAL` | hard_block | no | absolute path, drive letter or `..` segment in an entry name |
| `ZIP_SYMLINK` | hard_block | no | symlink entry (external_attr mode bits) |
| `ZIP_TOO_MANY_ENTRIES` | hard_block | no | entry count over cap |
| `ZIP_ENTRY_TOO_LARGE` | hard_block | no | entry over per-entry uncompressed cap (declared or while streaming) |
| `ZIP_UNCOMPRESSED_TOO_LARGE` | hard_block | no | total uncompressed size over cap |
| `ZIP_BOMB_RATIO` | hard_block | no | compression ratio over cap (entries > 1 MiB only) |
| `ZIP_ENCRYPTED_ENTRY` | hard_block | no | zip-level encryption flag set |
| `ZIP_MEMBER_MISSING` / `ZIP_MEMBER_UNREADABLE` | hard_block | no | member vanished / CRC or read error |
| `ZIP_DUPLICATE_ENTRY` | reviewable | yes | duplicate entry names in the archive |
| `MIMETYPE_INVALID` | reviewable | yes | `mimetype` entry missing, compressed, not first, or wrong content |
| `CONTAINER_XML_MALFORMED` | reviewable | yes | container.xml unusable; fell back to the single `.opf` |
| `EPUB_CONTAINER_UNREADABLE` | hard_block | no | no container.xml and no unambiguous `.opf` fallback |
| `EPUB_OPF_UNREADABLE` | hard_block | no | OPF package document unparseable |
| `DRM_ENCRYPTED_CONTENT` | reviewable | **no** | encryption.xml covers content resources (or is unreadable — fail closed) |
| `FONT_OBFUSCATION` | warning | yes | encryption.xml lists only font-obfuscation algorithms |
| `METADATA_FIELD_TRUNCATED` | warning | yes | metadata field over per-field byte cap, truncated |
| `METADATA_INCOMPLETE` | warning | yes | missing/empty title, language or identifier (never blocks) |
| `NAV_MALFORMED` | reviewable | yes | TOC (nav/NCX) missing or malformed; spine readable |
| `XHTML_NOT_WELL_FORMED` | warning | yes | content doc failed XML parse; stdlib HTML fallback used |
| `REMOTE_RESOURCE_REFERENCE` | warning | yes | http(s) resource refs in a content doc (never fetched); once per doc |
| `IMAGE_ONLY_CONTENT` | warning | yes | spine docs with images and <200 chars of text; `book_level` when >50% of docs |
| `SPINE_RESOURCE_MISSING` | reviewable | yes | spine idref/file missing from manifest/archive |
| `SPINE_ARTIFACTS_MISSING` | hard_block | no | `/epub/structure` called before `/epub/normalize` |
| `PARSE_TIMEOUT` | hard_block | no | stage exceeded `WORKER_PARSE_TIMEOUT_SECONDS` |
| `INTERNAL_ERROR` | hard_block | no | unexpected exception (HTTP 500) |

## Limits (env vars, conservative defaults)

| Variable | Default |
|---|---|
| `WORKER_DATA_PATH` | `/data` |
| `MNEMOSYNE_INTERNAL_TOKEN` | *(unset — API answers 503)* |
| `WORKER_MAX_EPUB_COMPRESSED_BYTES` | `200000000` |
| `WORKER_MAX_EPUB_UNCOMPRESSED_BYTES` | `1000000000` |
| `WORKER_MAX_ZIP_ENTRIES` | `10000` |
| `WORKER_MAX_ENTRY_UNCOMPRESSED_BYTES` | `300000000` |
| `WORKER_MAX_COMPRESSION_RATIO` | `200.0` (enforced only for entries > 1 MiB uncompressed) |
| `WORKER_MAX_METADATA_FIELD_BYTES` | `65536` |
| `WORKER_PARSE_TIMEOUT_SECONDS` | `300` |

The timeout is cooperative: stages run in FastAPI's threadpool and check
a deadline at spine-item and stage boundaries (documented in
`app/routers/internal_v1.py`), which cannot leak abandoned threads.

## Artifacts

Written atomically (`<name>.tmp-<pid>` + fsync + `os.replace`) under
`artifact_dir`, directories mode `0750`:

- `metadata.json` — normalized + raw OPF metadata + provenance
  (`source: epub_opf`, `opf_path`, `handler_version`)
- `spine/NNNN.jsonl` — one node per line:
  `{node_id, spine_index, ordinal, type, level, text, heading_path,
  source: {href, fragment}, lang, linear, char_count, has_image}`
- `structure.json` — sections, TOC mapping, spine summary, fingerprint
- `manifest.json` — `{asset_ref, pipeline_version, source_sha256,
  generated_at, stages, outputs (path/sha256/bytes), warnings}`;
  rewritten completely at each stage completion, rebuilt if corrupt.

## Determinism guarantees

Same input file + same handler versions ⇒ byte-identical spine JSONL and
`structure.json`, identical `content_sha256`, identical node ids
(`n{spine:04d}-{ordinal:06d}`, global ordinal ascending from 0).
No randomness, no dict-order dependence, no timestamps in artifact
content — timestamps appear only in `manifest.json` and the
`metadata.json` provenance block.

Content fingerprint (`FINGERPRINT_VERSION = "1"`, see
`app/epub/structure.py`): sha256 over the UTF-8 normalized text of block
nodes of types heading/paragraph/list/list_item/blockquote/table/figure/
caption/code — excluding figure nodes carrying an image (alt text tracks
artwork, not content) and excluding non-linear (`linear="no"`) spine
docs — joined with `\n` in ascending ordinal order. Independent of
metadata, cover, CSS and packaging.

## Dependencies / licensing

- **`defusedxml`** (PSF license, permissive): the only XML entry point;
  blocks entity expansion, external entities and DTD retrieval.
- **NOT `ebooklib`** — AGPL, incompatible with this codebase's licensing
  policy. **NOT `lxml` / BeautifulSoup / pandas / ML packages** — the
  pipeline is implemented on the Python stdlib (`zipfile`,
  `html.parser`, `unicodedata`, `hashlib`, …) plus the existing FastAPI
  stack, keeping the attack and supply-chain surface minimal.
- XHTML content documents are parsed as XML via defusedxml first; on
  failure a stdlib `html.parser`-based tolerant extractor takes over and
  an `XHTML_NOT_WELL_FORMED` warning is recorded.

## Tests / lint

From the repository root:

```bash
make test-python
make lint-python
```
