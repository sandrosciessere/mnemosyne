"""Normalization: spine-ordered block extraction into deterministic nodes.

Node ids are ``n{spine_index:04d}-{ordinal:06d}`` with a single global
ordinal counter across the whole book (ascending reading order, starting
at 0). Non-linear spine items are included but marked ``linear: false``.
The heading stack (for ``heading_path``) resets at each spine document.
"""

import json
import zipfile
from dataclasses import dataclass, field

from app.config import Limits
from app.epub.issues import Deadline, Issue, reviewable, warning
from app.epub.opf import PackageDoc, resolve_href
from app.epub.safety import read_member
from app.epub.xhtml import extract_blocks

_TEXTUAL_MEDIA_TYPES = {"application/xhtml+xml", "text/html", "application/x-dtbook+xml"}
_IMAGE_MEDIA_PREFIX = "image/"
_IMAGE_ONLY_CHAR_THRESHOLD = 200


@dataclass
class DocResult:
    spine_index: int
    href: str | None
    linear: bool
    nodes: list[dict] = field(default_factory=list)
    char_count: int = 0
    has_images: bool = False

    @property
    def image_only(self) -> bool:
        return self.char_count < _IMAGE_ONLY_CHAR_THRESHOLD and self.has_images


def node_id(spine_index: int, ordinal: int) -> str:
    return f"n{spine_index:04d}-{ordinal:06d}"


def normalize_book(
    zf: zipfile.ZipFile,
    package: PackageDoc,
    limits: Limits,
    issues: list[Issue],
    deadline: Deadline,
) -> list[DocResult]:
    names = set(zf.namelist())
    docs: list[DocResult] = []
    missing: list[str] = []
    ordinal = 0

    for spine_index, ref in enumerate(package.spine):
        deadline.check()
        item = package.manifest.get(ref.idref)
        href = resolve_href(package.opf_path, item.href) if item else None
        doc = DocResult(spine_index=spine_index, href=href, linear=ref.linear)
        docs.append(doc)

        if item is None or href is None or href not in names:
            missing.append(item.href if item else ref.idref)
            continue

        if item.media_type.startswith(_IMAGE_MEDIA_PREFIX) or item.media_type == "image/svg+xml":
            doc.has_images = True
            continue
        if item.media_type not in _TEXTUAL_MEDIA_TYPES:
            continue

        data = read_member(zf, href, limits)
        blocks = extract_blocks(data, href, issues)

        heading_stack: list[tuple[int, str]] = []
        for block in blocks:
            if block.type == "heading" and block.level is not None:
                heading_stack = [entry for entry in heading_stack if entry[0] < block.level]
                heading_stack.append((block.level, block.text))
            node = {
                "node_id": node_id(spine_index, ordinal),
                "spine_index": spine_index,
                "ordinal": ordinal,
                "type": block.type,
                "level": block.level,
                "text": block.text,
                "heading_path": [title for _level, title in heading_stack],
                "source": {"href": href, "fragment": block.fragment},
                "lang": block.lang,
                "linear": ref.linear,
                "char_count": len(block.text),
                "has_image": block.has_image,
            }
            doc.nodes.append(node)
            doc.char_count += len(block.text)
            doc.has_images = doc.has_images or block.has_image
            ordinal += 1

    if missing:
        issues.append(
            reviewable(
                "SPINE_RESOURCE_MISSING",
                "spine references resources missing from the archive",
                overrideable=True,
                resources=missing,
            )
        )

    image_only_hrefs = [doc.href for doc in docs if doc.image_only and doc.href]
    if image_only_hrefs:
        issues.append(
            warning(
                "IMAGE_ONLY_CONTENT",
                "spine documents contain images with little or no extractable text",
                hrefs=image_only_hrefs,
                book_level=len(image_only_hrefs) > len(docs) / 2,
            )
        )

    return docs


def doc_to_jsonl(doc: DocResult) -> bytes:
    """Serialize a spine document's nodes as deterministic JSONL bytes."""
    lines = [
        json.dumps(node, ensure_ascii=False, separators=(",", ":")).encode("utf-8") + b"\n" for node in doc.nodes
    ]
    return b"".join(lines)


def spine_artifact_name(spine_index: int) -> str:
    return f"spine/{spine_index:04d}.jsonl"
