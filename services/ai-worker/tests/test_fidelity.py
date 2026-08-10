"""Source-fidelity and citation-readiness coverage (normalizer 1.1.0).

Covers: canonical.txt == fingerprint corpus (sha equality), per-node
unicode-codepoint offsets, source_hash, refs/noteref extraction, is_note,
structured tables, figure/svg image fields, the HTML fallback path and
byte-level determinism, plus the FINGERPRINT_VERSION=1 corpus regression.
"""

import hashlib
import io
import json
import zipfile

from app.config import get_limits
from app.epub.container import locate_opf
from app.epub.issues import Deadline
from app.epub.normalize import apply_canonical_offsets, normalize_book, source_hash
from app.epub.opf import parse_opf
from app.epub.safety import read_member
from app.epub.structure import FINGERPRINT_VERSION, content_fingerprint, fingerprint_included
from app.epub.xhtml import extract_blocks
from tests import epub_builders as builders
from tests.conftest import AUTH

# content_sha256 of builders.build_epub3() produced by the 1.0.0 pipeline.
# FINGERPRINT_VERSION stays "1": the corpus definition must not change.
EPUB3_FINGERPRINT_V1 = "d8428dff361b10491593b14e4dfb4f49e50e436c91f1d7a783a94cf83894d5a5"


def _normalize(data: bytes):
    limits = get_limits()
    issues = []
    with zipfile.ZipFile(io.BytesIO(data)) as zf:
        opf_path = locate_opf(zf, limits, issues)
        package = parse_opf(read_member(zf, opf_path, limits), opf_path, limits, issues)
        docs = normalize_book(zf, package, limits, issues, Deadline.start(60))
    return docs, issues


def _payload(rel: str, artifact_dir: str = "artifacts/rich") -> dict:
    return {
        "asset_ref": "asset-rich",
        "relative_path": rel,
        "artifact_dir": artifact_dir,
        "pipeline_version": "pv-1",
        "source_sha256": "0" * 64,
    }


def _run_pipeline(client, install_epub, data_root, epub_bytes: bytes, artifact_dir: str = "artifacts/rich"):
    rel = install_epub(epub_bytes)
    payload = _payload(rel, artifact_dir)
    normalize_resp = client.post("/internal/v1/epub/normalize", json=payload, headers=AUTH).json()
    assert normalize_resp["status"] in ("passed", "passed_with_warnings")
    structure_resp = client.post("/internal/v1/epub/structure", json=payload, headers=AUTH).json()
    assert structure_resp["status"] in ("passed", "passed_with_warnings")
    return data_root / artifact_dir, normalize_resp, structure_resp


def _load_nodes(artifact_dir, count: int) -> list[dict]:
    nodes: list[dict] = []
    for index in range(count):
        with open(artifact_dir / f"spine/{index:04d}.jsonl", encoding="utf-8") as fh:
            nodes.extend(json.loads(line) for line in fh if line.strip())
    return nodes


# --- fingerprint / canonical invariants --------------------------------------


def test_fingerprint_regression_v1_corpus():
    assert FINGERPRINT_VERSION == "1"
    docs, _issues = _normalize(builders.build_epub3())
    nodes = [node for doc in docs for node in doc.nodes]
    assert content_fingerprint(nodes) == EPUB3_FINGERPRINT_V1


def test_canonical_txt_sha_equals_content_sha256(client, install_epub, data_root):
    artifact_dir, _norm, structure_resp = _run_pipeline(client, install_epub, data_root, builders.build_rich_epub())
    canonical = (artifact_dir / "canonical.txt").read_bytes()
    assert hashlib.sha256(canonical).hexdigest() == structure_resp["result"]["content_sha256"]


def test_canonical_txt_sha_equals_content_sha256_standard(client, install_epub, data_root):
    artifact_dir, _norm, structure_resp = _run_pipeline(
        client, install_epub, data_root, builders.build_epub3(), artifact_dir="artifacts/std"
    )
    canonical = (artifact_dir / "canonical.txt").read_bytes()
    assert hashlib.sha256(canonical).hexdigest() == structure_resp["result"]["content_sha256"]
    assert structure_resp["result"]["content_sha256"] == EPUB3_FINGERPRINT_V1


def test_offsets_roundtrip_every_node(client, install_epub, data_root):
    rich = builders.build_rich_epub()
    artifact_dir, normalize_resp, _structure = _run_pipeline(client, install_epub, data_root, rich)
    assert normalize_resp["result"]["spine_documents"] == 9
    canonical = (artifact_dir / "canonical.txt").read_bytes().decode("utf-8")
    nodes = _load_nodes(artifact_dir, 9)
    assert nodes
    included = excluded = 0
    for node in nodes:
        if fingerprint_included(node):
            included += 1
            start, end = node["normalized_start"], node["normalized_end"]
            assert isinstance(start, int) and isinstance(end, int)
            assert canonical[start:end] == node["text"]
        else:
            excluded += 1
            assert node["normalized_start"] is None
            assert node["normalized_end"] is None
    assert included > 0
    assert excluded > 0  # non-linear colophon and image-only figures
    # multilingual round-trip: non-Latin node texts slice exactly (codepoints)
    greek = next(node for node in nodes if node["lang"] == "el")
    assert "Ελληνικό" in greek["text"]
    assert canonical[greek["normalized_start"] : greek["normalized_end"]] == greek["text"]
    cjk = next(node for node in nodes if node["lang"] == "zh")
    assert canonical[cjk["normalized_start"] : cjk["normalized_end"]] == cjk["text"]
    # nodes from the non-linear doc are excluded
    colophon = [node for node in nodes if not node["linear"]]
    assert colophon and all(node["normalized_start"] is None for node in colophon)


def test_offsets_unit_level_match_helper():
    docs, _issues = _normalize(builders.build_rich_epub())
    canonical = apply_canonical_offsets(docs)
    assert hashlib.sha256(canonical.encode("utf-8")).hexdigest() == content_fingerprint(
        [node for doc in docs for node in doc.nodes]
    )
    for doc in docs:
        for node in doc.nodes:
            if node["normalized_start"] is not None:
                assert canonical[node["normalized_start"] : node["normalized_end"]] == node["text"]


# --- source_hash --------------------------------------------------------------


def test_source_hash_definition():
    docs, _issues = _normalize(builders.build_epub3())
    node = docs[0].nodes[0]
    expected = hashlib.sha256(
        f"{node['source']['href']}\x00{node['source']['fragment'] or ''}\x00{node['type']}\x00{node['text']}".encode()
    ).hexdigest()
    assert node["source_hash"] == expected
    assert source_hash(node["source"]["href"], node["source"]["fragment"], node["type"], node["text"]) == expected


def test_source_hash_stable_unless_text_changes():
    docs_a, _ = _normalize(builders.build_epub3(ch2_word="steady"))
    docs_b, _ = _normalize(builders.build_epub3(ch2_word="brisk"))
    # untouched chapter one: identical hashes across regenerations
    hashes_a = [node["source_hash"] for node in docs_a[0].nodes]
    hashes_b = [node["source_hash"] for node in docs_b[0].nodes]
    assert hashes_a == hashes_b
    # edited chapter two paragraph: hash changes
    assert docs_a[1].nodes[1]["text"] != docs_b[1].nodes[1]["text"]
    assert docs_a[1].nodes[1]["source_hash"] != docs_b[1].nodes[1]["source_hash"]


# --- refs / notes -------------------------------------------------------------


def test_noteref_and_cross_doc_link_refs():
    blocks = extract_blocks(builders.rich_notes_host().encode(), "OEBPS/text/rich3.xhtml", [])
    claim = next(block for block in blocks if "claim needing" in block.text)
    assert {"kind": "noteref", "href": "OEBPS/text/notes.xhtml", "fragment": "fn1"} in claim.refs
    see = next(block for block in blocks if block.text.startswith("See"))
    assert see.refs == [{"kind": "link", "href": "OEBPS/text/rich1.xhtml", "fragment": "rh2"}]


def test_remote_links_not_in_refs():
    blocks = extract_blocks(builders.rich_media().encode(), "OEBPS/text/rich5.xhtml", [])
    links = next(block for block in blocks if "external" in block.text)
    assert links.refs == [{"kind": "link", "href": "OEBPS/text/rich1.xhtml", "fragment": "rh1"}]


def test_same_doc_fragment_ref_has_null_href():
    blocks = extract_blocks(builders.rich_links().encode(), "OEBPS/text/rich7.xhtml", [])
    para = next(block for block in blocks if block.text.startswith("See"))
    assert {"kind": "link", "href": None, "fragment": "local"} in para.refs
    assert {"kind": "link", "href": "OEBPS/text/notes.xhtml", "fragment": "fn1"} in para.refs


def test_footnote_aside_marks_is_note():
    blocks = extract_blocks(builders.rich_notes().encode(), "OEBPS/text/notes.xhtml", [])
    heading = next(block for block in blocks if block.type == "heading")
    assert heading.is_note is False
    note = next(block for block in blocks if "supporting footnote" in block.text)
    assert note.is_note is True
    assert note.refs == [{"kind": "link", "href": "OEBPS/text/rich3.xhtml", "fragment": "ref1"}]


def test_is_note_serialized_in_nodes(client, install_epub, data_root):
    artifact_dir, _norm, _structure = _run_pipeline(client, install_epub, data_root, builders.build_rich_epub())
    nodes = _load_nodes(artifact_dir, 9)
    notes = [node for node in nodes if node["is_note"]]
    assert notes
    assert all(node["source"]["href"] == "OEBPS/text/notes.xhtml" for node in notes)
    noterefs = [
        ref for node in nodes for ref in (node["refs"] or []) if ref["kind"] == "noteref"
    ]
    assert {"kind": "noteref", "href": "OEBPS/text/notes.xhtml", "fragment": "fn1"} in noterefs


# --- tables / figures ---------------------------------------------------------


def test_table_structured_field_and_flat_text():
    blocks = extract_blocks(builders.rich_table().encode(), "OEBPS/text/rich4.xhtml", [])
    table = next(block for block in blocks if block.type == "table")
    assert table.text == "Name\tValue\nRow one\t1\nRow two\t2"
    assert table.table == {
        "caption": "Sample caption",
        "rows": [["Name", "Value"], ["Row one", "1"], ["Row two", "2"]],
    }
    # non-table nodes carry no structured table
    assert all(block.table is None for block in blocks if block.type != "table")


def test_figure_image_and_inline_svg_alt():
    blocks = extract_blocks(builders.rich_media().encode(), "OEBPS/text/rich5.xhtml", [])
    fig = next(block for block in blocks if block.fragment == "fig-img")
    assert fig.type == "figure" and fig.has_image
    assert fig.image == {"href": "OEBPS/images/cover.png", "alt": "Cover art"}
    svg_fig = next(block for block in blocks if block.fragment == "fig-svg")
    assert svg_fig.type == "figure" and svg_fig.has_image
    assert svg_fig.text == "Tiny circle"
    assert svg_fig.image == {"href": None, "alt": "Tiny circle"}
    remote_fig = next(block for block in blocks if block.fragment == "rem")
    assert remote_fig.type == "figure"
    assert remote_fig.image == {"href": None, "alt": "remote art"}  # remote src is dropped


# --- heading depth / fallback -------------------------------------------------


def test_deep_heading_path_h1_to_h4():
    docs, _issues = _normalize(builders.build_rich_epub())
    rich1 = docs[0].nodes
    deepest = next(node for node in rich1 if node["text"] == "Deepest detail text.")
    assert deepest["heading_path"] == ["Part One", "Chapter A", "Topic A.1", "Detail A.1.a"]


def test_fallback_doc_extracts_nodes_with_offsets(client, install_epub, data_root):
    rich = builders.build_rich_epub()
    artifact_dir, normalize_resp, _structure = _run_pipeline(client, install_epub, data_root, rich)
    codes = {issue["code"] for issue in normalize_resp["issues"]}
    assert "XHTML_NOT_WELL_FORMED" in codes
    with open(artifact_dir / "spine/0006.jsonl", encoding="utf-8") as fh:
        nodes = [json.loads(line) for line in fh if line.strip()]
    assert any("First broken paragraph." in node["text"] for node in nodes)
    canonical = (artifact_dir / "canonical.txt").read_bytes().decode("utf-8")
    for node in nodes:
        if node["normalized_start"] is not None:
            assert canonical[node["normalized_start"] : node["normalized_end"]] == node["text"]
    assert (artifact_dir / "sanitized/0006.xhtml").is_file()


# --- determinism --------------------------------------------------------------


def test_two_runs_produce_byte_identical_artifacts(client, install_epub, data_root):
    rel = install_epub(builders.build_rich_epub())
    payload = _payload(rel)
    assert client.post("/internal/v1/epub/normalize", json=payload, headers=AUTH).json()["status"] in (
        "passed",
        "passed_with_warnings",
    )
    artifact_dir = data_root / "artifacts/rich"
    tracked = sorted(
        path for path in artifact_dir.rglob("*") if path.is_file() and path.name != "manifest.json"
    )
    first = {path: path.read_bytes() for path in tracked}
    assert client.post("/internal/v1/epub/normalize", json=payload, headers=AUTH).json()["status"] in (
        "passed",
        "passed_with_warnings",
    )
    for path, data in first.items():
        assert path.read_bytes() == data, f"artifact changed between runs: {path}"
    assert any("sanitized/" in str(path) for path in tracked)
    assert any(path.name == "canonical.txt" for path in tracked)
