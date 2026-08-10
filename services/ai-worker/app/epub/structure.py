"""Structure extraction: sections, TOC mapping and the content fingerprint.

FINGERPRINT_VERSION = '1':
    content_sha256 = sha256 over the UTF-8 bytes of the normalized text of
    every block node of type heading/paragraph/list/list_item/blockquote/
    table/figure/caption/code — EXCLUDING figure nodes that carry an image
    (their text is alt text, which tracks the cover/artwork, not content)
    and EXCLUDING nodes from non-linear (linear="no") spine documents —
    joined with a single '\\n' in ascending ordinal order.

The fingerprint is therefore independent of metadata, cover, CSS and
packaging; identical text yields an identical fingerprint.
"""

import hashlib

FINGERPRINT_VERSION = "1"

_FINGERPRINT_TYPES = {
    "heading",
    "paragraph",
    "list",
    "list_item",
    "blockquote",
    "table",
    "figure",
    "caption",
    "code",
}


def content_fingerprint(nodes: list[dict]) -> str:
    """nodes: all book nodes in ascending ordinal order."""
    texts = [
        node["text"]
        for node in sorted(nodes, key=lambda n: n["ordinal"])
        if node["linear"]
        and node["type"] in _FINGERPRINT_TYPES
        and not (node["type"] == "figure" and node["has_image"])
    ]
    return hashlib.sha256("\n".join(texts).encode("utf-8")).hexdigest()


def build_sections(docs: list[tuple[int, list[dict]]]) -> list[dict]:
    """Sections are contiguous node ranges opened by heading nodes.

    A section closes when a heading of the same or shallower level appears,
    or at the end of its spine document. ``docs`` is a list of
    (spine_index, nodes) in spine order.
    """
    sections: list[dict] = []

    for spine_index, nodes in docs:
        open_stack: list[dict] = []

        def close(section: dict) -> None:
            sections.append(section)

        for node in nodes:
            if node["type"] == "heading" and node["level"] is not None:
                while open_stack and open_stack[-1]["level"] >= node["level"]:
                    close(open_stack.pop())
                open_stack.append(
                    {
                        "section_id": f"s{spine_index:04d}-{node['ordinal']:06d}",
                        "title": node["text"],
                        "heading_path": node["heading_path"],
                        "spine_index": spine_index,
                        "level": node["level"],
                        "start_ordinal": node["ordinal"],
                        "end_ordinal": node["ordinal"],
                        "node_count": 0,
                        "char_count": 0,
                    }
                )
            for section in open_stack:
                section["end_ordinal"] = node["ordinal"]
                section["node_count"] += 1
                section["char_count"] += node["char_count"]
        while open_stack:
            close(open_stack.pop())

    sections.sort(key=lambda s: s["start_ordinal"])
    return sections


def map_toc(entries: list[dict], href_to_spine: dict[str, int]) -> list[dict]:
    """Annotate a TOC tree with resolved spine indexes (None when unresolvable)."""
    mapped: list[dict] = []
    for entry in entries:
        mapped.append(
            {
                "title": entry["title"],
                "href": entry["href"],
                "fragment": entry["fragment"],
                "spine_index": href_to_spine.get(entry["href"]) if entry["href"] else None,
                "children": map_toc(entry["children"], href_to_spine),
            }
        )
    return mapped


def toc_stats(entries: list[dict], depth: int = 1) -> dict:
    stats = {"entries": 0, "resolved": 0, "max_depth": 0}
    for entry in entries:
        stats["entries"] += 1
        stats["max_depth"] = max(stats["max_depth"], depth)
        if entry["spine_index"] is not None:
            stats["resolved"] += 1
        child_stats = toc_stats(entry["children"], depth + 1)
        stats["entries"] += child_stats["entries"]
        stats["resolved"] += child_stats["resolved"]
        stats["max_depth"] = max(stats["max_depth"], child_stats["max_depth"])
    return stats


def build_structure(
    spine_docs: list[dict],
    docs_nodes: list[tuple[int, list[dict]]],
    toc: list[dict],
    landmarks: list[dict],
    toc_source: str | None,
) -> dict:
    """Assemble the deterministic structure.json payload (no timestamps).

    ``spine_docs``: [{spine_index, href, linear, node_count, char_count}]
    ``docs_nodes``: [(spine_index, nodes)] in spine order.
    """
    all_nodes = [node for _index, nodes in docs_nodes for node in nodes]
    href_to_spine = {doc["href"]: doc["spine_index"] for doc in spine_docs if doc["href"]}
    mapped_toc = map_toc(toc, href_to_spine)
    mapped_landmarks = map_toc(landmarks, href_to_spine)
    sections = build_sections(docs_nodes)

    return {
        "fingerprint_version": FINGERPRINT_VERSION,
        "content_sha256": content_fingerprint(all_nodes),
        "counts": {
            "spine_documents": len(spine_docs),
            "nodes": len(all_nodes),
            "chars": sum(node["char_count"] for node in all_nodes),
            "sections": len(sections),
            "toc_entries": toc_stats(mapped_toc)["entries"],
        },
        "toc_source": toc_source,
        "spine": spine_docs,
        "sections": sections,
        "toc": mapped_toc,
        "landmarks": mapped_landmarks,
    }
