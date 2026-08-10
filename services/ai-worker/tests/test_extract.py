import io
import zipfile

from app.config import get_limits
from app.epub.container import locate_opf
from app.epub.nav import extract_toc
from app.epub.opf import parse_opf
from app.epub.safety import read_member
from app.epub.xhtml import extract_blocks
from tests import epub_builders as builders


def _blocks(xhtml: str, issues=None):
    return extract_blocks(xhtml.encode("utf-8"), "text/doc.xhtml", issues if issues is not None else [])


def test_chapter_one_block_sequence():
    issues = []
    blocks = _blocks(builders.chapter_one(), issues)
    assert issues == []
    assert [(b.type, b.level) for b in blocks] == [
        ("heading", 1),
        ("paragraph", None),
        ("heading", 2),
        ("paragraph", None),
        ("blockquote", None),
        ("list_item", None),
        ("list_item", None),
    ]
    assert blocks[0].text == "Chapter One"
    # inline markup merged, whitespace collapsed
    assert blocks[3].text == "A second paragraph with emphasis and a link."
    assert blocks[4].text == "A quoted passage used for testing."
    assert [b.text for b in blocks[5:]] == ["First item", "Second item"]


def test_fragment_anchors():
    blocks = _blocks(builders.chapter_one())
    fragments = [b.fragment for b in blocks]
    # own id -> own id -> own id -> own id -> nearest preceding heading id
    assert fragments[0] == "ch1-title"
    assert fragments[1] == "ch1-title"  # paragraph without id: preceding heading
    assert fragments[2] == "sec1"
    assert fragments[3] == "p-key"  # own id wins
    assert fragments[4] == "sec1"  # blockquote inherits preceding heading id
    assert fragments[5] == "sec1"


def test_language_propagation():
    blocks = _blocks(builders.chapter_one())
    assert all(b.lang == "en" for b in blocks)
    xhtml = '<html xmlns="http://www.w3.org/1999/xhtml"><body><p xml:lang="fr">Texte.</p><p>Plain.</p></body></html>'
    blocks = _blocks(xhtml)
    assert blocks[0].lang == "fr"
    assert blocks[1].lang is None


def test_table_rows_tab_separated():
    blocks = _blocks(builders.chapter_three())
    table = next(b for b in blocks if b.type == "table")
    assert table.text == "A\tB\nC\tD"


def test_scripts_and_styles_stripped():
    xhtml = (
        '<html xmlns="http://www.w3.org/1999/xhtml"><body>'
        "<script>alert('x')</script><style>p{}</style>"
        "<p>Visible text.</p>"
        '<iframe src="page.html">hidden</iframe>'
        "</body></html>"
    )
    blocks = _blocks(xhtml)
    assert [b.text for b in blocks] == ["Visible text."]


def test_remote_resource_reference_warned_once():
    issues = []
    xhtml = (
        '<html xmlns="http://www.w3.org/1999/xhtml"><body>'
        '<p><img src="http://example.invalid/a.png" alt="a"/></p>'
        '<p><img src="https://example.invalid/b.png" alt="b"/></p>'
        "</body></html>"
    )
    _blocks(xhtml, issues)
    assert [i.code for i in issues] == ["REMOTE_RESOURCE_REFERENCE"]


def test_image_only_paragraph_becomes_figure():
    xhtml = (
        '<html xmlns="http://www.w3.org/1999/xhtml"><body>'
        '<p><img src="images/pic.png" alt="A described image"/></p>'
        "</body></html>"
    )
    blocks = _blocks(xhtml)
    assert len(blocks) == 1
    assert blocks[0].type == "figure"
    assert blocks[0].has_image is True
    assert blocks[0].text == "A described image"


def test_figure_and_caption():
    xhtml = (
        '<html xmlns="http://www.w3.org/1999/xhtml"><body>'
        '<figure id="fig1"><img src="images/pic.png" alt="Alt text"/>'
        "<figcaption>The caption.</figcaption></figure>"
        "</body></html>"
    )
    blocks = _blocks(xhtml)
    assert [(b.type, b.text) for b in blocks] == [("figure", "Alt text"), ("caption", "The caption.")]
    assert blocks[0].fragment == "fig1"


def test_malformed_xhtml_falls_back_with_warning():
    issues = []
    broken = "<html><body><h1 id='t'>Title<p>First paragraph.<p>Second paragraph.</body>"
    blocks = extract_blocks(broken.encode(), "text/bad.xhtml", issues)
    assert [i.code for i in issues] == ["XHTML_NOT_WELL_FORMED"]
    types = [b.type for b in blocks]
    assert "heading" in types
    texts = " ".join(b.text for b in blocks)
    assert "First paragraph." in texts
    assert "Second paragraph." in texts


def test_empty_blocks_skipped():
    xhtml = '<html xmlns="http://www.w3.org/1999/xhtml"><body><p>  </p><p></p><p>Real.</p></body></html>'
    blocks = _blocks(xhtml)
    assert [b.text for b in blocks] == ["Real."]


def _toc_for(data: bytes):
    limits = get_limits()
    issues = []
    with zipfile.ZipFile(io.BytesIO(data)) as zf:
        opf_path = locate_opf(zf, limits, issues)
        package = parse_opf(read_member(zf, opf_path, limits), opf_path, limits, issues)
        return extract_toc(zf, package, limits, issues), issues


def test_epub3_nav_toc_tree():
    result, issues = _toc_for(builders.build_epub3())
    assert issues == []
    assert result.source == "nav"
    assert len(result.toc) == 3
    first = result.toc[0]
    assert first["title"] == "Chapter One"
    assert first["href"] == "OEBPS/text/ch1.xhtml"
    assert first["fragment"] == "ch1-title"
    assert first["children"][0]["title"] == "Section One Point One"
    assert first["children"][0]["fragment"] == "sec1"
    assert result.landmarks[0]["title"] == "Start"


def test_epub2_ncx_toc_tree():
    result, issues = _toc_for(builders.build_epub2())
    assert issues == []
    assert result.source == "ncx"
    assert len(result.toc) == 2
    assert result.toc[0]["title"] == "Chapter One"
    assert result.toc[0]["children"][0]["title"] == "Section One Point One"
    assert result.toc[0]["children"][0]["href"] == "OEBPS/text/ch1.xhtml"


def test_missing_nav_is_reviewable():
    # strip the nav from the manifest by pointing nav_id at a missing file
    data = builders.build_epub3()
    limits = get_limits()
    issues = []
    with zipfile.ZipFile(io.BytesIO(data)) as zf:
        opf_path = locate_opf(zf, limits, issues)
        package = parse_opf(read_member(zf, opf_path, limits), opf_path, limits, issues)
        package.manifest["nav"].href = "missing-nav.xhtml"
        result = extract_toc(zf, package, limits, issues)
    codes = [issue.code for issue in issues]
    assert "NAV_MALFORMED" in codes
    issue = next(i for i in issues if i.code == "NAV_MALFORMED")
    assert issue.severity == "reviewable"
    assert issue.overrideable is True
    assert result.toc == []
