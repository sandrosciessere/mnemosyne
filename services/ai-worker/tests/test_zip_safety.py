import zipfile

import pytest

from app.config import get_limits
from app.epub.issues import EpubFailure
from app.epub.safety import DecompressionBudget, inspect_zip, read_member
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


# --- streaming enforcement (read_member): the real defence -------------------
#
# A truly "lying" small central-directory file_size is not exploitable through
# stdlib zipfile: ZipExtFile stops returning after file_size bytes, so a
# header that understates the real inflated size just truncates the read. The
# genuine defence is therefore counting ACTUAL decompressed bytes as they
# stream, enforced per-member (max_bytes) and cumulatively (DecompressionBudget)
# — never trusting the header size. These tests exercise both.


def test_streaming_per_member_cap_counts_actual_decompressed_bytes(tmp_path):
    path = _write(tmp_path, builders.build_epub3())
    limits = get_limits()  # generous default per-entry / header limits
    with zipfile.ZipFile(path) as zf:
        name = next(info.filename for info in zf.infolist() if info.filename.endswith("ch1.xhtml"))
        assert zf.getinfo(name).file_size > 64  # header check under defaults would pass
        with pytest.raises(EpubFailure) as excinfo:
            read_member(zf, name, limits, max_bytes=64)
    assert excinfo.value.issue.code == "ZIP_ENTRY_TOO_LARGE"
    assert excinfo.value.issue.overrideable is False
    assert excinfo.value.issue.details["limit"] == 64


def test_cumulative_decompression_budget_trips_across_members(tmp_path):
    path = _write(tmp_path, builders.build_epub3())
    limits = get_limits()
    with zipfile.ZipFile(path) as zf:
        textual = [info.filename for info in zf.infolist() if info.filename.endswith(".xhtml")]
        assert len(textual) >= 2
        largest = max(zf.getinfo(name).file_size for name in textual)
        # Any single member fits under the cap; their honest sum does not, so
        # only the cumulative budget (not a per-member cap) can catch this.
        budget = DecompressionBudget(largest + 8)
        fully_read = 0
        with pytest.raises(EpubFailure) as excinfo:
            for name in textual:
                read_member(zf, name, limits, budget=budget)
                fully_read += 1
    assert excinfo.value.issue.code == "ZIP_UNCOMPRESSED_TOO_LARGE"
    assert excinfo.value.issue.overrideable is False
    assert fully_read >= 1  # first member passed; a later one tripped the sum
    assert budget.consumed > budget.cap
