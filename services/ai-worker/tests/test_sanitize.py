"""Sanitized source XHTML artifacts: inertness, fidelity and traceability."""

import json

from defusedxml import ElementTree as DET

from app.epub.sanitize import (
    REMOVED_REMOTE_ATTR,
    SOURCE_HREF_ATTR,
    SPINE_INDEX_ATTR,
    sanitize_document,
)
from tests import epub_builders as builders
from tests.conftest import AUTH

_REMOTE_PREFIXES = ("http://", "https://", "ftp://", "//")
_FORBIDDEN_TAGS = {"script", "style", "template", "iframe", "object", "embed", "form", "input", "button", "base"}


def _local(tag: str) -> str:
    return tag.rsplit("}", 1)[-1] if tag.startswith("{") else tag.rsplit(":", 1)[-1]


def _assert_inert(root) -> None:
    for el in root.iter():
        assert _local(el.tag).lower() not in _FORBIDDEN_TAGS, f"forbidden element survived: {el.tag}"
        for name, value in el.attrib.items():
            assert not _local(name).lower().startswith("on"), f"event attribute survived: {name}"
            assert not value.strip().lower().startswith("javascript:"), f"javascript: URL survived: {value}"
            if _local(name).lower() in ("src", "href", "poster", "data"):
                assert not value.strip().lower().startswith(_REMOTE_PREFIXES), f"remote URL survived: {value}"


def _ids(root) -> set[str]:
    return {el.attrib["id"] for el in root.iter() if "id" in el.attrib}


# --- unit level ---------------------------------------------------------------


def test_sanitize_removes_script_events_and_remote_refs():
    data = builders.rich_media().encode()
    out = sanitize_document(data, "OEBPS/text/rich5.xhtml", 5)
    root = DET.fromstring(out)  # well-formed XML by construction
    _assert_inert(root)
    # ids preserved
    assert {"mh", "fig-img", "fig-svg", "rem", "evt", "links"} <= _ids(root)
    # relative refs preserved untouched
    hrefs = [el.attrib.get("src") or el.attrib.get("href") for el in root.iter()]
    assert "../images/cover.png" in hrefs
    assert "rich1.xhtml#rh1" in hrefs
    # remote img: attribute dropped and the element marked
    marked = [el for el in root.iter() if el.attrib.get(REMOVED_REMOTE_ATTR) == "1"]
    assert marked
    assert all("src" not in el.attrib for el in marked if _local(el.tag) == "img")


def test_sanitize_traceability_attributes():
    out = sanitize_document(builders.chapter_one().encode(), "OEBPS/text/ch1.xhtml", 0)
    root = DET.fromstring(out)
    assert root.attrib[SOURCE_HREF_ATTR] == "OEBPS/text/ch1.xhtml"
    assert root.attrib[SPINE_INDEX_ATTR] == "0"
    assert out.startswith(b'<?xml version="1.0" encoding="UTF-8"?>\n')


def test_sanitize_preserves_epub_type_lang_and_anchors():
    out = sanitize_document(builders.rich_notes().encode(), "OEBPS/text/notes.xhtml", 3)
    root = DET.fromstring(out)
    aside = next(el for el in root.iter() if _local(el.tag) == "aside")
    epub_type = next(
        (
            value
            for name, value in aside.attrib.items()
            if name in ("{http://www.idpf.org/2007/ops}type", "epub:type")
        ),
        None,
    )
    assert epub_type == "footnote"
    assert aside.attrib["id"] == "fn1"
    anchor = next(el for el in root.iter() if _local(el.tag) == "a")
    assert anchor.attrib["href"] == "rich3.xhtml#ref1"

    multilingual = sanitize_document(builders.rich_multilingual().encode(), "OEBPS/text/rich2.xhtml", 1)
    ml_root = DET.fromstring(multilingual)
    langs = {
        value for el in ml_root.iter() for name, value in el.attrib.items() if _local(name) == "lang"
    }
    assert {"el", "ru", "zh"} <= langs


def test_sanitize_javascript_scheme_and_meta_refresh():
    doc = (
        '<html xmlns="http://www.w3.org/1999/xhtml"><head>'
        '<meta http-equiv="refresh" content="0;url=http://example.invalid/"/></head>'
        '<body><p><a href="javascript:alert(1)" id="js">x</a>'
        '<a href="JAVASCRIPT:alert(2)">y</a></p></body></html>'
    )
    root = DET.fromstring(sanitize_document(doc.encode(), "OEBPS/a.xhtml", 0))
    _assert_inert(root)
    assert all(_local(el.tag) != "meta" for el in root.iter())
    js_anchor = next(el for el in root.iter() if el.attrib.get("id") == "js")
    assert "href" not in js_anchor.attrib


def test_sanitize_protocol_relative_and_ftp():
    doc = (
        '<html xmlns="http://www.w3.org/1999/xhtml"><body>'
        '<p><img src="//example.invalid/p.png" id="a"/>'
        '<img src="ftp://example.invalid/q.png" id="b"/>'
        '<img src="images/ok.png" id="c"/></p></body></html>'
    )
    root = DET.fromstring(sanitize_document(doc.encode(), "OEBPS/a.xhtml", 0))
    _assert_inert(root)
    by_id = {el.attrib.get("id"): el for el in root.iter() if el.attrib.get("id")}
    assert "src" not in by_id["a"].attrib and by_id["a"].attrib.get(REMOVED_REMOTE_ATTR) == "1"
    assert "src" not in by_id["b"].attrib and by_id["b"].attrib.get(REMOVED_REMOTE_ATTR) == "1"
    assert by_id["c"].attrib["src"] == "images/ok.png"


def test_sanitize_fallback_path_is_still_inert_and_well_formed():
    out = sanitize_document(builders.RICH_BROKEN.encode(), "OEBPS/text/rich6.xhtml", 6)
    root = DET.fromstring(out)  # must parse: well-formed
    _assert_inert(root)
    assert root.attrib[SOURCE_HREF_ATTR] == "OEBPS/text/rich6.xhtml"
    assert root.attrib[SPINE_INDEX_ATTR] == "6"
    # ids and relative anchors survive best-effort reconstruction
    assert "bt" in _ids(root)
    anchors = [el.attrib.get("href") for el in root.iter() if _local(el.tag) == "a"]
    assert "rich1.xhtml#rh1" in anchors
    # remote img neutralized even on the fallback path
    assert any(el.attrib.get(REMOVED_REMOTE_ATTR) == "1" for el in root.iter())
    text = "".join(root.itertext())
    assert "First broken paragraph." in text


def test_sanitize_fallback_script_content_never_leaks():
    broken = "<html><body><p>ok<script>var leak = 'SECRETPAYLOAD';</script><p>more"
    out = sanitize_document(broken.encode(), "OEBPS/b.xhtml", 0)
    assert b"SECRETPAYLOAD" not in out
    assert b"<script" not in out.lower()
    _assert_inert(DET.fromstring(out))


def test_sanitize_is_deterministic():
    for doc, href in ((builders.rich_media(), "OEBPS/text/rich5.xhtml"), (builders.RICH_BROKEN, "OEBPS/x.xhtml")):
        assert sanitize_document(doc.encode(), href, 2) == sanitize_document(doc.encode(), href, 2)


# --- artifact level -----------------------------------------------------------


def test_sanitized_artifacts_written_for_all_content_docs(client, install_epub, data_root):
    rel = install_epub(builders.build_rich_epub())
    payload = {
        "asset_ref": "asset-rich",
        "relative_path": rel,
        "artifact_dir": "artifacts/rich",
        "pipeline_version": "pv-1",
        "source_sha256": "0" * 64,
    }
    body = client.post("/internal/v1/epub/normalize", json=payload, headers=AUTH).json()
    assert body["status"] in ("passed", "passed_with_warnings")
    assert body["result"]["sanitized_documents"] == 9
    artifact_dir = data_root / "artifacts/rich"
    for index in range(9):
        out = (artifact_dir / f"sanitized/{index:04d}.xhtml").read_bytes()
        root = DET.fromstring(out)
        _assert_inert(root)
        assert root.attrib[SPINE_INDEX_ATTR] == str(index)
        assert root.attrib[SOURCE_HREF_ATTR].startswith("OEBPS/text/")
    manifest = json.loads((artifact_dir / "manifest.json").read_text())
    output_paths = {entry["path"] for entry in manifest["outputs"]}
    assert {f"sanitized/{index:04d}.xhtml" for index in range(9)} <= output_paths
    assert "canonical.txt" in output_paths
