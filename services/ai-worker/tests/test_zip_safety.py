import pytest

from app.config import get_limits
from app.epub.issues import EpubFailure
from app.epub.safety import inspect_zip
from tests import epub_builders as builders


def _codes(issues):
    return {issue.code for issue in issues}


def _write(tmp_path, data: bytes):
    path = tmp_path / "book.epub"
    path.write_bytes(data)
    return path


def test_clean_epub_has_no_issues(tmp_path):
    path = _write(tmp_path, builders.build_epub3())
    report, issues = inspect_zip(path, get_limits())
    assert issues == []
    assert report.entry_count == 9
    assert report.total_uncompressed_bytes > 0


def test_not_a_zip_raises_hard_failure(tmp_path):
    path = _write(tmp_path, builders.build_not_a_zip())
    with pytest.raises(EpubFailure) as excinfo:
        inspect_zip(path, get_limits())
    assert excinfo.value.issue.code == "ZIP_INVALID"


def test_path_traversal_rejected(tmp_path):
    path = _write(tmp_path, builders.build_path_traversal_epub())
    _report, issues = inspect_zip(path, get_limits())
    assert "ZIP_PATH_TRAVERSAL" in _codes(issues)
    issue = next(i for i in issues if i.code == "ZIP_PATH_TRAVERSAL")
    assert issue.severity == "hard_block"
    assert issue.overrideable is False
    assert "../evil.txt" in issue.details["entries"]


def test_absolute_path_rejected(tmp_path):
    path = _write(tmp_path, builders.build_absolute_path_epub())
    _report, issues = inspect_zip(path, get_limits())
    assert "ZIP_PATH_TRAVERSAL" in _codes(issues)


def test_symlink_entry_rejected(tmp_path):
    path = _write(tmp_path, builders.build_symlink_epub())
    _report, issues = inspect_zip(path, get_limits())
    issue = next(i for i in issues if i.code == "ZIP_SYMLINK")
    assert issue.severity == "hard_block"
    assert "OEBPS/evil-link" in issue.details["entries"]


def test_encrypted_zip_entry_rejected(tmp_path):
    path = _write(tmp_path, builders.build_encrypted_zip_entry_epub())
    _report, issues = inspect_zip(path, get_limits())
    assert "ZIP_ENCRYPTED_ENTRY" in _codes(issues)


def test_too_many_entries(tmp_path, monkeypatch):
    monkeypatch.setenv("WORKER_MAX_ZIP_ENTRIES", "3")
    path = _write(tmp_path, builders.build_epub3())
    _report, issues = inspect_zip(path, get_limits())
    assert "ZIP_TOO_MANY_ENTRIES" in _codes(issues)


def test_entry_too_large(tmp_path, monkeypatch):
    monkeypatch.setenv("WORKER_MAX_ENTRY_UNCOMPRESSED_BYTES", "64")
    path = _write(tmp_path, builders.build_epub3())
    _report, issues = inspect_zip(path, get_limits())
    assert "ZIP_ENTRY_TOO_LARGE" in _codes(issues)


def test_total_uncompressed_too_large(tmp_path, monkeypatch):
    monkeypatch.setenv("WORKER_MAX_EPUB_UNCOMPRESSED_BYTES", "1024")
    path = _write(tmp_path, builders.build_epub3())
    _report, issues = inspect_zip(path, get_limits())
    assert "ZIP_UNCOMPRESSED_TOO_LARGE" in _codes(issues)


def test_compression_ratio_bomb(tmp_path):
    path = _write(tmp_path, builders.build_zip_bomb_like())
    report, issues = inspect_zip(path, get_limits())
    issue = next(i for i in issues if i.code == "ZIP_BOMB_RATIO")
    assert issue.severity == "hard_block"
    assert "OEBPS/huge.bin" in issue.details["entries"]
    assert report.max_compression_ratio > 200


def test_small_highly_compressible_entries_are_not_flagged(tmp_path):
    # tiny compressible files (< 1 MiB) never trigger the ratio check
    data = builders._zip_bytes(builders._epub3_entries() + [("OEBPS/small.bin", b"\0" * 4096)])
    path = _write(tmp_path, data)
    _report, issues = inspect_zip(path, get_limits())
    assert "ZIP_BOMB_RATIO" not in _codes(issues)


def test_duplicate_entries_reviewable(tmp_path):
    path = _write(tmp_path, builders.build_duplicate_entry_epub())
    _report, issues = inspect_zip(path, get_limits())
    issue = next(i for i in issues if i.code == "ZIP_DUPLICATE_ENTRY")
    assert issue.severity == "reviewable"
    assert issue.overrideable is True
    assert issue.details["entries"] == ["OEBPS/style.css"]


def test_compressed_file_size_cap(tmp_path, monkeypatch):
    monkeypatch.setenv("WORKER_MAX_EPUB_COMPRESSED_BYTES", "10")
    path = _write(tmp_path, builders.build_epub3())
    _report, issues = inspect_zip(path, get_limits())
    assert "EPUB_FILE_TOO_LARGE" in _codes(issues)
