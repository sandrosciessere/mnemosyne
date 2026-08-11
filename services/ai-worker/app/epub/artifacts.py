"""Atomic artifact writing under the data root.

Every file is written to ``<name>.tmp-<pid>`` in the destination
directory, flushed, fsynced and then ``os.replace``d into place, so
readers never observe partial files. Directories are created with mode
0o750. ``manifest.json`` is rewritten completely on each stage completion
and rebuilt from scratch when absent or corrupt.
"""

import hashlib
import json
import os
from datetime import UTC, datetime
from pathlib import Path

METADATA_FILE = "metadata.json"
STRUCTURE_FILE = "structure.json"
MANIFEST_FILE = "manifest.json"


def ensure_dir(path: Path) -> None:
    to_create: list[Path] = []
    current = path
    while not current.exists():
        to_create.append(current)
        if current.parent == current:
            break
        current = current.parent
    path.mkdir(mode=0o750, parents=True, exist_ok=True)
    for created in to_create:
        try:
            os.chmod(created, 0o750)
        except OSError:
            pass


def write_bytes_atomic(path: Path, data: bytes) -> None:
    ensure_dir(path.parent)
    tmp = path.parent / f"{path.name}.tmp-{os.getpid()}"
    with open(tmp, "wb") as fh:
        fh.write(data)
        fh.flush()
        os.fsync(fh.fileno())
    os.replace(tmp, path)


def write_json_atomic(path: Path, payload: dict) -> None:
    data = json.dumps(payload, ensure_ascii=False, sort_keys=True, indent=2).encode("utf-8") + b"\n"
    write_bytes_atomic(path, data)


def file_sha256(path: Path) -> str:
    digest = hashlib.sha256()
    with open(path, "rb") as fh:
        for chunk in iter(lambda: fh.read(65_536), b""):
            digest.update(chunk)
    return digest.hexdigest()


def utc_now() -> str:
    return datetime.now(UTC).isoformat(timespec="seconds")


def update_manifest(
    artifact_dir: Path,
    *,
    asset_ref: str,
    pipeline_version: str,
    source_sha256: str,
    stage: str,
    handler_version: str,
    duration_ms: int,
    outputs: list[str],
    issues: list[dict],
) -> dict:
    """Read-modify-write manifest.json; rebuilds it when absent or corrupt.

    ``outputs`` are paths relative to ``artifact_dir`` produced by this
    stage; their sha256/bytes are recomputed now. Entries for other stages
    are preserved; entries for this stage are replaced.
    """
    manifest_path = artifact_dir / MANIFEST_FILE
    manifest: dict = {}
    if manifest_path.exists():
        try:
            with open(manifest_path, encoding="utf-8") as fh:
                loaded = json.load(fh)
            if isinstance(loaded, dict):
                manifest = loaded
        except (OSError, ValueError):
            manifest = {}

    stages = manifest.get("stages") if isinstance(manifest.get("stages"), dict) else {}
    existing_outputs = manifest.get("outputs") if isinstance(manifest.get("outputs"), list) else []
    warnings = manifest.get("warnings") if isinstance(manifest.get("warnings"), list) else []

    stages[stage] = {
        "handler_version": handler_version,
        "completed_at": utc_now(),
        "duration_ms": duration_ms,
    }

    outputs_by_path: dict[str, dict] = {}
    for entry in existing_outputs:
        if isinstance(entry, dict) and isinstance(entry.get("path"), str):
            outputs_by_path[entry["path"]] = entry
    for rel in outputs:
        target = artifact_dir / rel
        outputs_by_path[rel] = {"path": rel, "sha256": file_sha256(target), "bytes": target.stat().st_size}

    warnings = [w for w in warnings if not (isinstance(w, dict) and w.get("stage") == stage)]
    warnings.extend({**issue, "stage": stage} for issue in issues)

    manifest = {
        "asset_ref": asset_ref,
        "pipeline_version": pipeline_version,
        "source_sha256": source_sha256,
        "generated_at": utc_now(),
        "stages": stages,
        "outputs": sorted(outputs_by_path.values(), key=lambda entry: entry["path"]),
        "warnings": warnings,
    }
    write_json_atomic(manifest_path, manifest)
    return manifest
