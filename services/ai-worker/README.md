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
| `/epub/normalize` | same as parse | writes `spine/NNNN.jsonl` node artifacts, `sanitized/NNNN.xhtml` source documents and `canonical.txt`; returns counts |
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

Handler versions (`app/versions.py`): `VALIDATOR_VERSION` `1.0.0`,
`PARSER_VERSION` `1.0.0`, `NORMALIZER_VERSION` `1.1.0`,
`STRUCTURER_VERSION` `1.1.0`. The 1.1.0 bump adds the sanitized source
artifacts, `canonical.txt` and the citation-oriented node fields below;
`FINGERPRINT_VERSION` stays `"1"` and `content_sha256` for a given file
is identical to the 1.0.0 output.

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
  source: {href, fragment}, lang, linear, char_count, has_image,
  is_note, refs, table, image, source_hash, normalized_start,
  normalized_end}` (see “Node fields” below)
- `sanitized/NNNN.xhtml` — sanitized copy of the ORIGINAL spine content
  document (same `NNNN` as the spine JSONL; written only for textual
  spine items). Well-formed XML, inert (see “Sanitization rules”), root
  carries `data-mnemosyne-source-href` (zip-internal spine href) and
  `data-mnemosyne-spine-index` for traceability.
- `canonical.txt` — UTF-8; EXACTLY the fingerprint corpus: the texts of
  the included nodes joined with single `\n` in ascending ordinal order
  (no trailing newline). Invariant: `sha256(canonical.txt bytes) ==
  content_sha256` from `structure.json`.
- `structure.json` — sections, TOC mapping, spine summary, fingerprint
- `manifest.json` — `{asset_ref, pipeline_version, source_sha256,
  generated_at, stages, outputs (path/sha256/bytes), warnings}`;
  rewritten completely at each stage completion, rebuilt if corrupt.

### Node fields added in normalizer 1.1.0

- `normalized_start` / `normalized_end` — **unicode codepoint** offsets
  into the decoded `canonical.txt` string such that
  `canonical_text[normalized_start:normalized_end] == text`. `null` for
  nodes excluded from the fingerprint corpus (figure nodes with
  `has_image`, and every node of a `linear: false` spine document).
  Offsets are assigned after the whole book is extracted, because the
  corpus spans all spine documents.
- `source_hash` — sha256 hex of the UTF-8 bytes of
  `f"{source_href}\x00{fragment or ''}\x00{node_type}\x00{text}"`.
  Stale-citation detector: deterministic and timestamp-free, so it is
  stable across artifact regenerations while the underlying source
  location and content are unchanged, and changes whenever the text,
  the anchor or the location changes.
- `refs` — list of `{kind: "link"|"noteref", href, fragment}` for every
  internal `<a>` (and `epub:type="noteref"` anchor) inside the block;
  `href` is the target document resolved to a zip-root path (same
  namespace as `source.href`), or `null` for same-document targets;
  `fragment` is the anchor or `null`. Remote/external links are never
  included (they are neutralized). `null` when the block has no
  internal links.
- `is_note` — `true` for nodes extracted from elements whose
  `epub:type` carries a note token (`footnote`, `endnote`, `note`,
  `rearnote`, …); text extraction is otherwise unchanged.
- `table` — on `table` nodes only:
  `{caption: string|null, rows: [[cell text, …], …]}` preserving
  row/cell order (`th` and `td` both as text). The flat tab/newline
  `text` behaviour is unchanged (fingerprint corpus is untouched).
- `image` — on figure nodes with `has_image`:
  `{href: string|null, alt: string|null}`; `href` is the image source
  resolved to a zip-root path (`null` for inline SVG or a neutralized
  remote source), `alt` from `img@alt` or the SVG `<title>`/`<desc>`.
  Inline SVG blocks are treated as figures.

### Sanitization rules (`sanitized/NNNN.xhtml`)

| Rule | Treatment |
|---|---|
| `script`, `style`, `template`, `iframe`, `object`, `embed`, `form`, `input`, `button`, `base`, `foreignObject` | subtree removed entirely |
| `meta http-equiv="refresh"` | removed |
| `audio` / `video` / `source` with remote references | removed |
| `on*` event attributes | removed |
| attribute values starting with `javascript:` (or `vbscript:`) | attribute removed |
| `src` / `href` / `xlink:href` / `poster` / `data` / `srcset` with `http:` / `https:` / `ftp:` scheme or protocol-relative `//` | attribute dropped (URL never kept); element marked `data-mnemosyne-removed-remote="1"` |
| comments, processing instructions, DTD | dropped (only our own XML declaration is emitted) |
| `id`, `epub:type`, `xml:lang`/`lang`, structural tags, internal/relative hrefs and fragment anchors, relative `img@src`, sanitized inline SVG | preserved unchanged |

Documents that fail XML parsing take the `html.parser` fallback (with
the existing `XHTML_NOT_WELL_FORMED` warning): a best-effort tree is
reconstructed and run through the **same** sanitization pass, so even
fallback output is well-formed XML and never contains scripts or remote
references.

## Determinism guarantees

Same input file + same handler versions ⇒ byte-identical spine JSONL,
`sanitized/NNNN.xhtml`, `canonical.txt` and
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
