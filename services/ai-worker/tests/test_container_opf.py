import io
import zipfile

import pytest

from app.config import get_limits
from app.epub.container import check_encryption, check_mimetype, locate_opf
from app.epub.issues import EpubFailure
from app.epub.opf import normalize_identifier, parse_opf
from app.epub.safety import read_member
from tests import epub_builders as builders


def _zf(data: bytes) -> zipfile.ZipFile:
    return zipfile.ZipFile(io.BytesIO(data))


def _package(data: bytes, issues=None):
    issues = issues if issues is not None else []
    limits = get_limits()
    with _zf(data) as zf:
        opf_path = locate_opf(zf, limits, issues)
        return parse_opf(read_member(zf, opf_path, limits), opf_path, limits, issues), issues


def test_mimetype_ok():
    with _zf(builders.build_epub3()) as zf:
        assert check_mimetype(zf, get_limits()) == []


def test_mimetype_wrong_is_reviewable():
    with _zf(builders.build_wrong_mimetype()) as zf:
        issues = check_mimetype(zf, get_limits())
    assert len(issues) == 1
    assert issues[0].code == "MIMETYPE_INVALID"
    assert issues[0].severity == "reviewable"
    assert issues[0].overrideable is True


def test_malformed_container_falls_back_to_single_opf():
    issues = []
    with _zf(builders.build_malformed_container()) as zf:
        opf_path = locate_opf(zf, get_limits(), issues)
    assert opf_path == "OEBPS/content.opf"
    assert [issue.code for issue in issues] == ["CONTAINER_XML_MALFORMED"]


def test_unreadable_container_with_ambiguous_opf_fails_hard():
    with _zf(builders.build_container_unreadable()) as zf:
        with pytest.raises(EpubFailure) as excinfo:
            locate_opf(zf, get_limits(), [])
    assert excinfo.value.issue.code == "EPUB_CONTAINER_UNREADABLE"


def test_malformed_opf_fails_hard():
    with pytest.raises(EpubFailure) as excinfo:
        _package(builders.build_malformed_opf())
    assert excinfo.value.issue.code == "EPUB_OPF_UNREADABLE"


def test_epub3_metadata_extraction():
    package, issues = _package(builders.build_epub3())
    assert package.epub_major == 3
    meta = package.metadata

    assert meta["title"] == "The Synthetic Book"
    assert meta["subtitle"] == "A Deterministic Subtitle"
    assert [t["type"] for t in meta["titles"]] == ["main", "subtitle"]

    creators = meta["creators"]
    assert creators[0]["name"] == "Alice Author"
    assert creators[0]["roles"] == ["aut"]
    assert creators[0]["file_as"] == "Author, Alice"
    assert creators[1]["roles"] == ["trl"]

    assert meta["languages"] == ["en"]
    assert meta["publisher"] == "Synthetic Press"
    assert meta["subjects"] == ["Testing", "Software"]
    assert meta["modified"] == "2020-02-02T00:00:00Z"
    assert meta["description"].startswith("A synthetic book")

    identifiers = meta["identifiers"]
    isbn = next(i for i in identifiers if i["scheme"] == "isbn13")
    assert isbn["value"] == builders.ISBN13
    assert isbn["unique"] is True
    uuid_id = next(i for i in identifiers if i["scheme"] == "uuid")
    assert uuid_id["value"] == builders.UUID_A

    assert package.cover_id == "cover-img"
    assert package.nav_id == "nav"
    assert [ref.idref for ref in package.spine] == ["c1", "c2", "c3"]
    assert all(ref.linear for ref in package.spine)
    assert issues == []


def test_epub2_metadata_extraction():
    package, _issues = _package(builders.build_epub2())
    assert package.epub_major == 2
    meta = package.metadata

    assert meta["title"] == "The Synthetic Book Two"
    creators = meta["creators"]
    assert creators[0]["roles"] == ["aut"]
    assert creators[0]["file_as"] == "Writer, Wanda"
    assert creators[1]["roles"] == ["ill"]
    assert meta["languages"] == ["it"]
    assert meta["dates"] == [{"value": "2010-05-05", "event": "publication"}]

    isbn = meta["identifiers"][0]
    assert isbn["scheme"] == "isbn10"
    assert isbn["value"] == builders.ISBN10
    assert isbn["isbn13"] == builders.ISBN13  # derived
    assert isbn["unique"] is True

    assert package.cover_id == "cover-img"  # EPUB2 meta name="cover"
    assert package.ncx_id == "ncx"


def test_raw_metadata_snapshots_preserved():
    package, _issues = _package(builders.build_epub3())
    tags = [entry["tag"] for entry in package.raw_metadata]
    assert "dc:title" in tags
    assert "meta" in tags
    creator_raw = next(e for e in package.raw_metadata if e["tag"] == "dc:creator")
    assert creator_raw["text"] == "Alice Author"


def test_identifier_normalization():
    good13 = normalize_identifier(f"urn:isbn:{builders.ISBN13}")
    assert good13["scheme"] == "isbn13"
    assert good13["valid"] is True
    assert good13["raw"] == f"urn:isbn:{builders.ISBN13}"

    bad13 = normalize_identifier(builders.ISBN13_BAD)
    assert bad13["scheme"] == "other"
    assert bad13["valid"] is False

    hyphenated = normalize_identifier("978-0-306-40615-7")
    assert hyphenated["scheme"] == "isbn13"
    assert hyphenated["value"] == builders.ISBN13

    isbn10 = normalize_identifier(builders.ISBN10, "ISBN")
    assert isbn10["scheme"] == "isbn10"
    assert isbn10["isbn13"] == builders.ISBN13

    uid = normalize_identifier(f"urn:uuid:{builders.UUID_A}")
    assert uid["scheme"] == "uuid"
    assert uid["value"] == builders.UUID_A

    doi = normalize_identifier("doi:10.1000/example.42")
    assert doi["scheme"] == "doi"
    assert doi["value"] == "10.1000/example.42"

    uri = normalize_identifier("https://example.invalid/book/1")
    assert uri["scheme"] == "uri"

    other = normalize_identifier("just-some-string")
    assert other["scheme"] == "other"
    assert other["raw"] == "just-some-string"


def test_metadata_field_truncation(monkeypatch):
    monkeypatch.setenv("WORKER_MAX_METADATA_FIELD_BYTES", "16")
    _package_doc, issues = _package(builders.build_epub3())
    codes = [issue.code for issue in issues]
    assert "METADATA_FIELD_TRUNCATED" in codes


def test_drm_encrypted_content():
    with _zf(builders.build_encrypted_content_epub()) as zf:
        issues = check_encryption(zf, get_limits())
    assert len(issues) == 1
    assert issues[0].code == "DRM_ENCRYPTED_CONTENT"
    assert issues[0].severity == "reviewable"
    assert issues[0].overrideable is False


def test_font_obfuscation_is_warning_only():
    with _zf(builders.build_font_obfuscation_epub()) as zf:
        issues = check_encryption(zf, get_limits())
    assert len(issues) == 1
    assert issues[0].code == "FONT_OBFUSCATION"
    assert issues[0].severity == "warning"


def test_no_encryption_xml_yields_no_issue():
    with _zf(builders.build_epub3()) as zf:
        assert check_encryption(zf, get_limits()) == []
