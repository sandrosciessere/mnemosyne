import hashlib
import io
import json
import zipfile

from app.config import get_limits
from app.epub.container import locate_opf
from app.epub.issues import Deadline
from app.epub.normalize import doc_to_jsonl, normalize_book
from app.epub.opf import parse_opf
from app.epub.safety import read_member
from app.epub.structure import build_sections, build_structure, content_fingerprint
from tests import epub_builders as builders


def _normalize(data: bytes):
    limits = get_limits()
    issues = []
    with zipfile.ZipFile(io.BytesIO(data)) as zf:
        opf_path = locate_opf(zf, limits, issues)
        package = parse_opf(read_member(zf, opf_path, limits), opf_path, limits, issues)
        docs = normalize_book(zf, package, limits, issues, Deadline.start(60))
    return docs, issues


def test_spine_order_respected_despite_zip_order():
    docs, issues = _normalize(builders.build_epub3())
    assert issues == []
    assert [doc.href for doc in docs] == [
        "OEBPS/text/ch1.xhtml",
        "OEBPS/text/ch2.xhtml",
        "OEBPS/text/ch3.xhtml",
    ]
    assert docs[0].nodes[0]["text"] == "Chapter One"
    assert docs[1].nodes[0]["text"] == "Chapter Two"


def test_node_ids_and_global_ordinals():
    docs, _issues = _normalize(builders.build_epub3())
    all_nodes = [node for doc in docs for node in doc.nodes]
    assert [node["ordinal"] for node in all_nodes] == list(range(len(all_nodes)))
    assert all_nodes[0]["node_id"] == "n0000-000000"
    first_ch2 = docs[1].nodes[0]
    assert first_ch2["node_id"] == f"n0001-{first_ch2['ordinal']:06d}"
    # id embeds spine index and global ordinal
    for doc in docs:
        for node in doc.nodes:
            assert node["node_id"] == f"n{node['spine_index']:04d}-{node['ordinal']:06d}"


def test_heading_path():
    docs, _issues = _normalize(builders.build_epub3())
    ch1 = docs[0].nodes
    assert ch1[0]["heading_path"] == ["Chapter One"]
    assert ch1[1]["heading_path"] == ["Chapter One"]
    assert ch1[2]["heading_path"] == ["Chapter One", "Section One Point One"]
    assert ch1[3]["heading_path"] == ["Chapter One", "Section One Point One"]
    # heading stack resets per spine document
    assert docs[1].nodes[1]["heading_path"] == ["Chapter Two"]


def test_node_shape_and_source():
    docs, _issues = _normalize(builders.build_epub3())
    node = docs[0].nodes[3]
    assert set(node.keys()) == {
        "node_id",
        "spine_index",
        "ordinal",
        "type",
        "level",
        "text",
        "heading_path",
        "source",
        "lang",
        "linear",
        "char_count",
        "has_image",
    }
    assert node["source"] == {"href": "OEBPS/text/ch1.xhtml", "fragment": "p-key"}
    assert node["linear"] is True
    assert node["char_count"] == len(node["text"])


def test_normalization_is_deterministic():
    payload_a = b"".join(doc_to_jsonl(doc) for doc in _normalize(builders.build_epub3())[0])
    payload_b = b"".join(doc_to_jsonl(doc) for doc in _normalize(builders.build_epub3())[0])
    assert payload_a == payload_b
    assert hashlib.sha256(payload_a).hexdigest() == hashlib.sha256(payload_b).hexdigest()


def test_fingerprint_ignores_cover_css_metadata():
    epub_a, epub_b = builders.build_same_text_different_cover()
    assert hashlib.sha256(epub_a).hexdigest() != hashlib.sha256(epub_b).hexdigest()

    docs_a, _ = _normalize(epub_a)
    docs_b, _ = _normalize(epub_b)
    nodes_a = [node for doc in docs_a for node in doc.nodes]
    nodes_b = [node for doc in docs_b for node in doc.nodes]
    assert content_fingerprint(nodes_a) == content_fingerprint(nodes_b)


def test_fingerprint_changes_on_one_word_edit():
    docs_a, _ = _normalize(builders.build_epub3(ch2_word="steady"))
    docs_b, _ = _normalize(builders.build_epub3(ch2_word="brisk"))
    nodes_a = [node for doc in docs_a for node in doc.nodes]
    nodes_b = [node for doc in docs_b for node in doc.nodes]
    assert content_fingerprint(nodes_a) != content_fingerprint(nodes_b)


def test_fingerprint_excludes_nonlinear_and_image_figures():
    nodes = [
        {"ordinal": 0, "type": "paragraph", "text": "kept", "linear": True, "has_image": False, "char_count": 4},
        {"ordinal": 1, "type": "paragraph", "text": "nonlinear", "linear": False, "has_image": False, "char_count": 9},
        {"ordinal": 2, "type": "figure", "text": "cover alt", "linear": True, "has_image": True, "char_count": 9},
        {"ordinal": 3, "type": "other", "text": "misc", "linear": True, "has_image": False, "char_count": 4},
    ]
    expected = hashlib.sha256(b"kept").hexdigest()
    assert content_fingerprint(nodes) == expected


def test_sections_from_headings():
    docs, _issues = _normalize(builders.build_epub3())
    sections = build_sections([(doc.spine_index, doc.nodes) for doc in docs])
    ch1_sections = [s for s in sections if s["spine_index"] == 0]
    assert [s["title"] for s in ch1_sections] == ["Chapter One", "Section One Point One"]
    top = ch1_sections[0]
    sub = ch1_sections[1]
    assert top["level"] == 1
    assert sub["level"] == 2
    # the h1 section spans the whole chapter, including the h2 subsection
    assert top["start_ordinal"] == 0
    assert top["end_ordinal"] == docs[0].nodes[-1]["ordinal"]
    assert top["node_count"] == len(docs[0].nodes)
    assert sub["start_ordinal"] == docs[0].nodes[2]["ordinal"]
    assert sub["node_count"] == len(docs[0].nodes) - 2
    assert top["char_count"] == sum(n["char_count"] for n in docs[0].nodes)
    assert top["section_id"] == "s0000-000000"


def test_structure_payload_is_deterministic():
    def build():
        docs, _ = _normalize(builders.build_epub3())
        spine_docs = [
            {
                "spine_index": doc.spine_index,
                "href": doc.href,
                "linear": doc.linear,
                "node_count": len(doc.nodes),
                "char_count": doc.char_count,
            }
            for doc in docs
        ]
        return build_structure(spine_docs, [(d.spine_index, d.nodes) for d in docs], [], [], None)

    payload_a = json.dumps(build(), sort_keys=True)
    payload_b = json.dumps(build(), sort_keys=True)
    assert payload_a == payload_b


def test_image_only_content_warning():
    entries = [e for e in builders._epub3_entries() if e[0] != "OEBPS/text/ch3.xhtml"]
    body = '<p><img src="../images/cover.png" alt="only art"/></p>'
    entries.append(("OEBPS/text/ch3.xhtml", builders._xhtml(body)))
    _docs, issues = _normalize(builders._zip_bytes(entries))
    issue = next(i for i in issues if i.code == "IMAGE_ONLY_CONTENT")
    assert issue.details["hrefs"] == ["OEBPS/text/ch3.xhtml"]
    assert issue.details["book_level"] is False
