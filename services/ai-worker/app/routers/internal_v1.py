"""Internal versioned EPUB processing API (/internal/v1).

Every endpoint is POST JSON, requires ``X-Mnemosyne-Internal-Token`` and
answers with the stage envelope::

    {"status", "stage", "handler_version", "duration_ms", "issues", "result"}

status: any hard_block -> "failed"; else any reviewable -> "needs_review";
else any warning -> "passed_with_warnings"; else "passed".

Timeout approach (documented): stages are synchronous ``def`` endpoints
executed in FastAPI's threadpool; a cooperative :class:`Deadline` created
from WORKER_PARSE_TIMEOUT_SECONDS is checked at spine-item and stage
boundaries. Exceeding it raises StageTimeout -> failed PARSE_TIMEOUT.
This is simpler and more robust than cancelling threads (which Python
cannot do safely) and cannot leak abandoned worker threads.
"""

import json
import logging
import time
import zipfile
from collections.abc import Callable
from pathlib import Path

from fastapi import APIRouter, Depends, HTTPException
from fastapi.responses import JSONResponse
from pydantic import BaseModel

from app import versions
from app.config import Limits, get_limits
from app.epub import artifacts, container, nav, normalize, opf, safety, structure
from app.epub.issues import (
    Deadline,
    EpubFailure,
    Issue,
    StageTimeout,
    derive_status,
    hard_block,
)
from app.security import PathValidationError, require_internal_token, resolve_data_path

logger = logging.getLogger("mnemosyne.internal_v1")

router = APIRouter(prefix="/internal/v1", dependencies=[Depends(require_internal_token)])


class ValidateRequest(BaseModel):
    asset_ref: str
    relative_path: str
    correlation_id: str | None = None


class StageRequest(BaseModel):
    asset_ref: str
    relative_path: str
    artifact_dir: str
    pipeline_version: str
    source_sha256: str
    correlation_id: str | None = None


def _execute(stage: str, handler_version: str, correlation_id: str | None, fn: Callable) -> JSONResponse:
    started = time.monotonic()
    issues: list[Issue] = []
    result = None
    http_status = 200

    try:
        result = fn(issues)
        status = derive_status(issues)
    except PathValidationError as exc:
        raise HTTPException(status_code=422, detail={"code": "PATH_INVALID", "message": str(exc)}) from exc
    except EpubFailure as exc:
        issues.append(exc.issue)
        status = "failed"
    except StageTimeout:
        issues.append(hard_block("PARSE_TIMEOUT", "stage exceeded WORKER_PARSE_TIMEOUT_SECONDS"))
        status = "failed"
    except HTTPException:
        raise
    except Exception:
        logger.exception("unexpected error in stage=%s correlation_id=%s", stage, correlation_id)
        issues.append(hard_block("INTERNAL_ERROR", "unexpected internal error"))
        status = "failed"
        result = None
        http_status = 500

    envelope = {
        "status": status,
        "stage": stage,
        "handler_version": handler_version,
        "duration_ms": int((time.monotonic() - started) * 1000),
        "issues": [issue.to_dict() for issue in issues],
        "result": result,
    }
    return JSONResponse(envelope, status_code=http_status)


def _load_package(zf: zipfile.ZipFile, limits: Limits, issues: list[Issue]) -> opf.PackageDoc:
    issues.extend(container.check_mimetype(zf, limits))
    opf_path = container.locate_opf(zf, limits, issues)
    issues.extend(container.check_encryption(zf, limits))
    opf_data = safety.read_member(zf, opf_path, limits)
    return opf.parse_opf(opf_data, opf_path, limits, issues)


def _inspect_or_fail(epub_path: Path, limits: Limits, issues: list[Issue]) -> safety.ZipReport | None:
    """Run zip safety checks; returns the report, or None when hard-blocked."""
    report, zip_issues = safety.inspect_zip(epub_path, limits)
    issues.extend(zip_issues)
    if any(issue.severity == "hard_block" for issue in zip_issues):
        return None
    return report


@router.post("/epub/validate")
def epub_validate(req: ValidateRequest) -> JSONResponse:
    def run(issues: list[Issue]):
        limits = get_limits()
        deadline = Deadline.start(limits.parse_timeout_seconds)
        epub_path = resolve_data_path(req.relative_path)
        report = _inspect_or_fail(epub_path, limits, issues)
        if report is None:
            return None
        deadline.check()
        with zipfile.ZipFile(epub_path) as zf:
            has_encryption_xml = "META-INF/encryption.xml" in zf.namelist()
            package = _load_package(zf, limits, issues)
        return {
            "zip": report.to_dict(),
            "opf_path": package.opf_path,
            "epub_version": package.version,
            "spine_count": len(package.spine),
            "linear_spine_count": sum(1 for ref in package.spine if ref.linear),
            "manifest_count": len(package.manifest),
            "has_encryption_xml": has_encryption_xml,
        }

    return _execute("validate", versions.VALIDATOR_VERSION, req.correlation_id, run)


@router.post("/epub/parse")
def epub_parse(req: StageRequest) -> JSONResponse:
    def run(issues: list[Issue]):
        started = time.monotonic()
        limits = get_limits()
        deadline = Deadline.start(limits.parse_timeout_seconds)
        epub_path = resolve_data_path(req.relative_path)
        artifact_dir = resolve_data_path(req.artifact_dir)
        report = _inspect_or_fail(epub_path, limits, issues)
        if report is None:
            return None
        deadline.check()
        with zipfile.ZipFile(epub_path) as zf:
            package = _load_package(zf, limits, issues)

        cover_item = package.manifest.get(package.cover_id) if package.cover_id else None
        metadata_payload = {
            "provenance": {
                "source": "epub_opf",
                "opf_path": package.opf_path,
                "handler_version": versions.PARSER_VERSION,
                "asset_ref": req.asset_ref,
                "pipeline_version": req.pipeline_version,
                "source_sha256": req.source_sha256,
                "generated_at": artifacts.utc_now(),
            },
            "epub_version": package.version,
            "unique_identifier_id": package.unique_identifier_id,
            "cover_href": cover_item.href if cover_item else None,
            "normalized": package.metadata,
            "raw": package.raw_metadata,
        }
        deadline.check()
        artifacts.write_json_atomic(artifact_dir / artifacts.METADATA_FILE, metadata_payload)
        artifacts.update_manifest(
            artifact_dir,
            asset_ref=req.asset_ref,
            pipeline_version=req.pipeline_version,
            source_sha256=req.source_sha256,
            stage="parse",
            handler_version=versions.PARSER_VERSION,
            duration_ms=int((time.monotonic() - started) * 1000),
            outputs=[artifacts.METADATA_FILE],
            issues=[issue.to_dict() for issue in issues],
        )
        return {
            "opf_path": package.opf_path,
            "epub_version": package.version,
            "metadata": package.metadata,
        }

    return _execute("parse", versions.PARSER_VERSION, req.correlation_id, run)


@router.post("/epub/normalize")
def epub_normalize(req: StageRequest) -> JSONResponse:
    def run(issues: list[Issue]):
        started = time.monotonic()
        limits = get_limits()
        deadline = Deadline.start(limits.parse_timeout_seconds)
        epub_path = resolve_data_path(req.relative_path)
        artifact_dir = resolve_data_path(req.artifact_dir)
        report = _inspect_or_fail(epub_path, limits, issues)
        if report is None:
            return None
        deadline.check()
        with zipfile.ZipFile(epub_path) as zf:
            package = _load_package(zf, limits, issues)
            docs = normalize.normalize_book(zf, package, limits, issues, deadline)

        outputs: list[str] = []
        for doc in docs:
            deadline.check()
            rel = normalize.spine_artifact_name(doc.spine_index)
            artifacts.write_bytes_atomic(artifact_dir / rel, normalize.doc_to_jsonl(doc))
            outputs.append(rel)
        artifacts.update_manifest(
            artifact_dir,
            asset_ref=req.asset_ref,
            pipeline_version=req.pipeline_version,
            source_sha256=req.source_sha256,
            stage="normalize",
            handler_version=versions.NORMALIZER_VERSION,
            duration_ms=int((time.monotonic() - started) * 1000),
            outputs=outputs,
            issues=[issue.to_dict() for issue in issues],
        )
        return {
            "spine_documents": len(docs),
            "nodes": sum(len(doc.nodes) for doc in docs),
            "chars": sum(doc.char_count for doc in docs),
            "image_only_documents": sum(1 for doc in docs if doc.image_only),
        }

    return _execute("normalize", versions.NORMALIZER_VERSION, req.correlation_id, run)


@router.post("/epub/structure")
def epub_structure(req: StageRequest) -> JSONResponse:
    def run(issues: list[Issue]):
        started = time.monotonic()
        limits = get_limits()
        deadline = Deadline.start(limits.parse_timeout_seconds)
        epub_path = resolve_data_path(req.relative_path)
        artifact_dir = resolve_data_path(req.artifact_dir)
        report = _inspect_or_fail(epub_path, limits, issues)
        if report is None:
            return None
        deadline.check()
        with zipfile.ZipFile(epub_path) as zf:
            package = _load_package(zf, limits, issues)
            toc_result = nav.extract_toc(zf, package, limits, issues)

        # Determinism & speed: nodes are re-read from the JSONL artifacts
        # produced by /epub/normalize — content documents are NOT re-parsed.
        docs_nodes: list[tuple[int, list[dict]]] = []
        spine_docs: list[dict] = []
        for spine_index, ref in enumerate(package.spine):
            deadline.check()
            spine_file = artifact_dir / normalize.spine_artifact_name(spine_index)
            if not spine_file.is_file():
                raise EpubFailure(
                    hard_block(
                        "SPINE_ARTIFACTS_MISSING",
                        "spine JSONL artifacts are missing; run /epub/normalize first",
                        missing=normalize.spine_artifact_name(spine_index),
                    )
                )
            with open(spine_file, encoding="utf-8") as fh:
                nodes = [json.loads(line) for line in fh if line.strip()]
            docs_nodes.append((spine_index, nodes))
            item = package.manifest.get(ref.idref)
            href = opf.resolve_href(package.opf_path, item.href) if item else None
            spine_docs.append(
                {
                    "spine_index": spine_index,
                    "href": href,
                    "linear": ref.linear,
                    "node_count": len(nodes),
                    "char_count": sum(node["char_count"] for node in nodes),
                }
            )

        payload = structure.build_structure(
            spine_docs, docs_nodes, toc_result.toc, toc_result.landmarks, toc_result.source
        )
        deadline.check()
        artifacts.write_json_atomic(artifact_dir / artifacts.STRUCTURE_FILE, payload)
        artifacts.update_manifest(
            artifact_dir,
            asset_ref=req.asset_ref,
            pipeline_version=req.pipeline_version,
            source_sha256=req.source_sha256,
            stage="structure",
            handler_version=versions.STRUCTURER_VERSION,
            duration_ms=int((time.monotonic() - started) * 1000),
            outputs=[artifacts.STRUCTURE_FILE],
            issues=[issue.to_dict() for issue in issues],
        )
        return {
            "content_sha256": payload["content_sha256"],
            "fingerprint_version": payload["fingerprint_version"],
            "counts": payload["counts"],
            "toc_summary": {**structure.toc_stats(payload["toc"]), "source": toc_result.source},
            "spine": spine_docs,
        }

    return _execute("structure", versions.STRUCTURER_VERSION, req.correlation_id, run)
