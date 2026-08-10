"""Zip-level safety inspection. Never extracts anything to disk.

``inspect_zip`` walks the central directory only; ``read_member`` is the
single sanctioned way to read a member and enforces both the per-entry
uncompressed cap and the cumulative :class:`DecompressionBudget` *while
reading* (zip metadata can lie about sizes, so only bytes actually
inflated are counted — never the attacker-controlled header ``file_size``).
"""

import re
import zipfile
from dataclasses import dataclass
from pathlib import Path

from app.config import Limits
from app.epub.issues import EpubFailure, Issue, hard_block, reviewable

_DRIVE_RE = re.compile(r"^[A-Za-z]:")
_CHUNK = 65_536


class DecompressionBudget:
    """Cumulative actual-decompressed-bytes cap for a single stage request.

    Central-directory sizes are attacker-controlled, so the real defence is
    counting bytes as they stream out of the inflater. One budget instance
    is threaded through every :func:`read_member` call of a request so that
    many individually-legal members cannot together exceed the cap. Tripping
    it raises the same terminal, non-overrideable hard block as the header
    total check.
    """

    def __init__(self, cap: int) -> None:
        self.cap = cap
        self.consumed = 0

    def charge(self, nbytes: int) -> None:
        self.consumed += nbytes
        if self.consumed > self.cap:
            raise EpubFailure(
                hard_block(
                    "ZIP_UNCOMPRESSED_TOO_LARGE",
                    "cumulative decompressed size exceeded the limit while reading",
                    bytes=self.consumed,
                    limit=self.cap,
                )
            )


@dataclass
class ZipReport:
    entry_count: int = 0
    total_compressed_bytes: int = 0
    total_uncompressed_bytes: int = 0
    max_compression_ratio: float = 0.0

    def to_dict(self) -> dict:
        return {
            "entry_count": self.entry_count,
            "total_compressed_bytes": self.total_compressed_bytes,
            "total_uncompressed_bytes": self.total_uncompressed_bytes,
            "max_compression_ratio": round(self.max_compression_ratio, 2),
        }


def _is_unsafe_path(name: str) -> bool:
    normalized = name.replace("\\", "/")
    if normalized.startswith("/") or _DRIVE_RE.match(normalized):
        return True
    return any(part == ".." for part in normalized.split("/"))


def _is_symlink(info: zipfile.ZipInfo) -> bool:
    return (info.external_attr >> 16) & 0o170000 == 0o120000


def inspect_zip(path: Path, limits: Limits) -> tuple[ZipReport, list[Issue]]:
    """Inspect zip metadata; returns (report, issues). Raises EpubFailure if not a zip."""
    issues: list[Issue] = []
    report = ZipReport()

    try:
        compressed_size = path.stat().st_size
    except OSError as exc:
        raise EpubFailure(hard_block("EPUB_FILE_NOT_FOUND", "EPUB file not found or unreadable")) from exc
    if compressed_size > limits.max_epub_compressed_bytes:
        issues.append(
            hard_block(
                "EPUB_FILE_TOO_LARGE",
                "EPUB file exceeds the compressed size limit",
                bytes=compressed_size,
                limit=limits.max_epub_compressed_bytes,
            )
        )
        return report, issues

    if not zipfile.is_zipfile(path):
        raise EpubFailure(hard_block("ZIP_INVALID", "file is not a valid zip archive"))

    unsafe_paths: list[str] = []
    symlinks: list[str] = []
    encrypted: list[str] = []
    too_large: list[str] = []
    bombs: list[str] = []
    duplicates: list[str] = []
    seen: set[str] = set()

    with zipfile.ZipFile(path) as zf:
        infos = zf.infolist()
        report.entry_count = len(infos)

        if len(infos) > limits.max_zip_entries:
            issues.append(
                hard_block(
                    "ZIP_TOO_MANY_ENTRIES",
                    "zip archive has too many entries",
                    entries=len(infos),
                    limit=limits.max_zip_entries,
                )
            )
            return report, issues

        for info in infos:
            name = info.filename
            if name in seen:
                duplicates.append(name)
            seen.add(name)

            if _is_unsafe_path(name):
                unsafe_paths.append(name)
            if _is_symlink(info):
                symlinks.append(name)
            if info.flag_bits & 0x1:
                encrypted.append(name)

            report.total_compressed_bytes += info.compress_size
            report.total_uncompressed_bytes += info.file_size

            if info.file_size > limits.max_entry_uncompressed_bytes:
                too_large.append(name)

            if info.file_size > limits.ratio_check_min_bytes:
                ratio = float(info.file_size) if info.compress_size == 0 else info.file_size / info.compress_size
                report.max_compression_ratio = max(report.max_compression_ratio, ratio)
                if ratio > limits.max_compression_ratio:
                    bombs.append(name)
            elif info.compress_size > 0:
                report.max_compression_ratio = max(report.max_compression_ratio, info.file_size / info.compress_size)

    if unsafe_paths:
        issues.append(
            hard_block("ZIP_PATH_TRAVERSAL", "zip entries with absolute or traversal paths", entries=unsafe_paths)
        )
    if symlinks:
        issues.append(hard_block("ZIP_SYMLINK", "zip contains symlink entries", entries=symlinks))
    if encrypted:
        issues.append(hard_block("ZIP_ENCRYPTED_ENTRY", "zip contains encrypted entries", entries=encrypted))
    if too_large:
        issues.append(
            hard_block(
                "ZIP_ENTRY_TOO_LARGE",
                "zip entry exceeds the per-entry uncompressed size limit",
                entries=too_large,
                limit=limits.max_entry_uncompressed_bytes,
            )
        )
    if report.total_uncompressed_bytes > limits.max_epub_uncompressed_bytes:
        issues.append(
            hard_block(
                "ZIP_UNCOMPRESSED_TOO_LARGE",
                "total uncompressed size exceeds the limit",
                bytes=report.total_uncompressed_bytes,
                limit=limits.max_epub_uncompressed_bytes,
            )
        )
    if bombs:
        issues.append(
            hard_block(
                "ZIP_BOMB_RATIO",
                "zip entry compression ratio exceeds the limit",
                entries=bombs,
                limit=limits.max_compression_ratio,
            )
        )
    if duplicates:
        issues.append(
            reviewable("ZIP_DUPLICATE_ENTRY", "zip contains duplicate entry names", entries=sorted(set(duplicates)))
        )

    return report, issues


def read_member(
    zf: zipfile.ZipFile,
    name: str,
    limits: Limits,
    max_bytes: int | None = None,
    budget: "DecompressionBudget | None" = None,
) -> bytes:
    """Read a member fully, enforcing the uncompressed caps while streaming.

    Two independent caps are enforced against bytes actually inflated (never
    the header ``file_size``): the per-member ``max_bytes`` (defaults to
    ``limits.max_entry_uncompressed_bytes``) and, when supplied, the
    cross-member cumulative ``budget`` for the whole request.
    """
    cap = max_bytes if max_bytes is not None else limits.max_entry_uncompressed_bytes
    chunks: list[bytes] = []
    total = 0
    try:
        with zf.open(name) as fh:
            while True:
                chunk = fh.read(_CHUNK)
                if not chunk:
                    break
                total += len(chunk)
                if total > cap:
                    raise EpubFailure(
                        hard_block(
                            "ZIP_ENTRY_TOO_LARGE",
                            "zip entry exceeded the size limit while reading",
                            entries=[name],
                            limit=cap,
                        )
                    )
                if budget is not None:
                    budget.charge(len(chunk))
                chunks.append(chunk)
    except KeyError as exc:
        raise EpubFailure(hard_block("ZIP_MEMBER_MISSING", "zip member not found", entries=[name])) from exc
    except (zipfile.BadZipFile, RuntimeError, OSError) as exc:
        raise EpubFailure(hard_block("ZIP_MEMBER_UNREADABLE", "zip member could not be read", entries=[name])) from exc
    return b"".join(chunks)
