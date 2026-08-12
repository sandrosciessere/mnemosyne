"""Environment-driven limits — single source of truth for the worker.

Every limit has a conservative default; values are read from the
environment at call time so tests (and operators) can adjust them without
restarting module state.
"""

import os
from dataclasses import dataclass
from pathlib import Path

# Compression-ratio checks only apply to entries larger than this many
# uncompressed bytes, to avoid false positives on tiny highly-compressible
# files (fixed, not env-tunable).
RATIO_CHECK_MIN_BYTES = 1_048_576  # 1 MiB

_DEFAULTS = {
    "WORKER_MAX_EPUB_COMPRESSED_BYTES": 200_000_000,
    "WORKER_MAX_EPUB_UNCOMPRESSED_BYTES": 1_000_000_000,
    "WORKER_MAX_ZIP_ENTRIES": 10_000,
    "WORKER_MAX_ENTRY_UNCOMPRESSED_BYTES": 300_000_000,
    "WORKER_MAX_COMPRESSION_RATIO": 200.0,
    "WORKER_MAX_METADATA_FIELD_BYTES": 65_536,
    "WORKER_PARSE_TIMEOUT_SECONDS": 300,
    "WORKER_EMBED_BATCH_SIZE": 16,
}


def _env_int(name: str) -> int:
    raw = os.environ.get(name, "")
    try:
        return int(raw)
    except (TypeError, ValueError):
        return int(_DEFAULTS[name])


def _env_float(name: str) -> float:
    raw = os.environ.get(name, "")
    try:
        return float(raw)
    except (TypeError, ValueError):
        return float(_DEFAULTS[name])


@dataclass(frozen=True)
class Limits:
    max_epub_compressed_bytes: int
    max_epub_uncompressed_bytes: int
    max_zip_entries: int
    max_entry_uncompressed_bytes: int
    max_compression_ratio: float
    max_metadata_field_bytes: int
    parse_timeout_seconds: float
    ratio_check_min_bytes: int = RATIO_CHECK_MIN_BYTES


def get_limits() -> Limits:
    return Limits(
        max_epub_compressed_bytes=_env_int("WORKER_MAX_EPUB_COMPRESSED_BYTES"),
        max_epub_uncompressed_bytes=_env_int("WORKER_MAX_EPUB_UNCOMPRESSED_BYTES"),
        max_zip_entries=_env_int("WORKER_MAX_ZIP_ENTRIES"),
        max_entry_uncompressed_bytes=_env_int("WORKER_MAX_ENTRY_UNCOMPRESSED_BYTES"),
        max_compression_ratio=_env_float("WORKER_MAX_COMPRESSION_RATIO"),
        max_metadata_field_bytes=_env_int("WORKER_MAX_METADATA_FIELD_BYTES"),
        parse_timeout_seconds=_env_float("WORKER_PARSE_TIMEOUT_SECONDS"),
    )


def get_data_root() -> Path:
    return Path(os.environ.get("WORKER_DATA_PATH", "/data"))


def get_internal_token() -> str:
    return os.environ.get("MNEMOSYNE_INTERNAL_TOKEN", "")


def get_embed_batch_size() -> int:
    value = _env_int("WORKER_EMBED_BATCH_SIZE")
    return value if value > 0 else int(_DEFAULTS["WORKER_EMBED_BATCH_SIZE"])


def get_hf_home() -> Path:
    """Hugging Face cache root (models live under <hf_home>/hub)."""
    return Path(os.environ.get("HF_HOME", "/data/models/hf"))


def allow_model_download() -> bool:
    """When false (default) model loading is strictly offline."""
    return os.environ.get("WORKER_ALLOW_MODEL_DOWNLOAD", "0") == "1"
