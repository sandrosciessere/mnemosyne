"""Internal-API authentication and data-path confinement."""

import hmac
import os
import re
from pathlib import Path, PurePosixPath
from typing import Annotated

from fastapi import Header, HTTPException

from app import config

_DRIVE_RE = re.compile(r"^[A-Za-z]:")


class PathValidationError(ValueError):
    """Raised when a caller-supplied relative path is unsafe (mapped to HTTP 422)."""


def require_internal_token(
    token: Annotated[str | None, Header(alias="X-Mnemosyne-Internal-Token")] = None,
) -> None:
    """Fail closed: 503 when no token is configured, 401 when missing/wrong."""
    expected = config.get_internal_token()
    if not expected:
        raise HTTPException(
            status_code=503,
            detail={"code": "TOKEN_NOT_CONFIGURED", "message": "internal token is not configured"},
        )
    if token is None or not hmac.compare_digest(token.encode("utf-8"), expected.encode("utf-8")):
        raise HTTPException(
            status_code=401,
            detail={"code": "UNAUTHORIZED", "message": "missing or invalid internal token"},
        )


def resolve_data_path(relative: str) -> Path:
    """Resolve a caller-supplied relative path against WORKER_DATA_PATH.

    Rejects absolute paths, backslashes, drive letters, '..' segments and
    empty input; the realpath of the result must stay inside the realpath
    of the data root (defends against symlink escapes).
    """
    if not isinstance(relative, str) or not relative.strip():
        raise PathValidationError("path must be a non-empty relative path")
    if "\\" in relative:
        raise PathValidationError("backslashes are not allowed in paths")
    if _DRIVE_RE.match(relative):
        raise PathValidationError("drive letters are not allowed in paths")
    pure = PurePosixPath(relative)
    if pure.is_absolute() or relative.startswith("/"):
        raise PathValidationError("absolute paths are not allowed")
    if any(part == ".." for part in pure.parts):
        raise PathValidationError("'..' segments are not allowed")

    root = Path(os.path.realpath(config.get_data_root()))
    candidate = Path(os.path.realpath(root / relative))
    if candidate != root and root not in candidate.parents:
        raise PathValidationError("path escapes the data root")
    return candidate
