import json

from tests import epub_builders as builders
from tests.conftest import AUTH


def _stage_payload(relative_path: str, artifact_dir: str = "artifacts/book1") -> dict:
    return {
        "asset_ref": "asset-0001",
        "relative_path": relative_path,
        "artifact_dir": artifact_dir,
        "pipeline_version": "pv-1",
        "source_sha256": "0" * 64,
        "correlation_id": "corr-123",
    }


# --- auth --------------------------------------------------------------------


def test_missing_token_is_401(client, install_epub):
    rel = install_epub(builders.build_epub3())
    response = client.post("/internal/v1/epub/validate", json={"asset_ref": "a", "relative_path": rel})
    assert response.status_code == 401


def test_wrong_token_is_401(client, install_epub):
    rel = install_epub(builders.build_epub3())
    response = client.post(
        "/internal/v1/epub/validate",
        json={"asset_ref": "a", "relative_path": rel},
        headers={"X-Mnemosyne-Internal-Token": "nope"},
    )
    assert response.status_code == 401


def test_unset_token_is_503(client, install_epub, monkeypatch):
    rel = install_epub(builders.build_epub3())
    monkeypatch.delenv("MNEMOSYNE_INTERNAL_TOKEN")
    response = client.post(
        "/internal/v1/epub/validate", json={"asset_ref": "a", "relative_path": rel}, headers=AUTH
    )
    assert response.status_code == 503


# --- path security -----------------------------------------------------------


def test_absolute_path_is_422(client):
    response = client.post(
        "/internal/v1/epub/validate",
        json={"asset_ref": "a", "relative_path": "/etc/passwd"},
        headers=AUTH,
    )
    assert response.status_code == 422


def test_dotdot_path_is_422(client):
    response = client.post(
        "/internal/v1/epub/validate",
        json={"asset_ref": "a", "relative_path": "../outside.epub"},
        headers=AUTH,
    )
    assert response.status_code == 422


def test_backslash_path_is_422(client):
    response = client.post(
        "/internal/v1/epub/validate",
        json={"asset_ref": "a", "relative_path": "assets\\book.epub"},
        headers=AUTH,
    )
    assert response.status_code == 422


def test_symlink_escape_is_422(client, data_root, tmp_path):
    outside = tmp_path / "outside"
    outside.mkdir()
    (outside / "secret.epub").write_bytes(builders.build_epub3())
    (data_root / "leak").symlink_to(outside)
    response = client.post(
        "/internal/v1/epub/validate",
        json={"asset_ref": "a", "relative_path": "leak/secret.epub"},
        headers=AUTH,
    )
    assert response.status_code == 422


# --- validate ----------------------------------------------------------------


def test_validate_clean_epub3(client, install_epub):
    rel = install_epub(builders.build_epub3())
    response = client.post(
        "/internal/v1/epub/validate", json={"asset_ref": "a", "relative_path": rel}, headers=AUTH
    )
    assert response.status_code == 200
    body = response.json()
    assert body["status"] == "passed"
    assert body["stage"] == "validate"
    assert body["handler_version"] == "1.0.0"
    assert body["issues"] == []
    assert body["result"]["spine_count"] == 3
    assert body["result"]["epub_version"] == "3.0"
    assert body["result"]["opf_path"] == "OEBPS/content.opf"
    assert body["result"]["has_encryption_xml"] is False
    assert isinstance(body["duration_ms"], int)


def test_validate_traversal_fails(client, install_epub):
    rel = install_epub(builders.build_path_traversal_epub())
    body = client.post(
        "/internal/v1/epub/validate", json={"asset_ref": "a", "relative_path": rel}, headers=AUTH
    ).json()
    assert body["status"] == "failed"
    codes = {issue["code"] for issue in body["issues"]}
    assert "ZIP_PATH_TRAVERSAL" in codes
    blocking = next(i for i in body["issues"] if i["code"] == "ZIP_PATH_TRAVERSAL")
    assert blocking["severity"] == "hard_block"
    assert blocking["overrideable"] is False


def test_validate_not_a_zip_fails(client, install_epub):
    rel = install_epub(builders.build_not_a_zip())
    body = client.post(
        "/internal/v1/epub/validate", json={"asset_ref": "a", "relative_path": rel}, headers=AUTH
    ).json()
    assert body["status"] == "failed"
    assert body["issues"][0]["code"] == "ZIP_INVALID"


def test_validate_drm_needs_review(client, install_epub):
    rel = install_epub(builders.build_encrypted_content_epub())
    body = client.post(
        "/internal/v1/epub/validate", json={"asset_ref": "a", "relative_path": rel}, headers=AUTH
    ).json()
    assert body["status"] == "needs_review"
    issue = next(i for i in body["issues"] if i["code"] == "DRM_ENCRYPTED_CONTENT")
    assert issue["overrideable"] is False


def test_validate_font_obfuscation_warns(client, install_epub):
    rel = install_epub(builders.build_font_obfuscation_epub())
    body = client.post(
        "/internal/v1/epub/validate", json={"asset_ref": "a", "relative_path": rel}, headers=AUTH
    ).json()
    assert body["status"] == "passed_with_warnings"
    assert body["issues"][0]["code"] == "FONT_OBFUSCATION"
    assert body["result"]["has_encryption_xml"] is True


def test_validate_malformed_container_needs_review(client, install_epub):
    rel = install_epub(builders.build_malformed_container())
    body = client.post(
        "/internal/v1/epub/validate", json={"asset_ref": "a", "relative_path": rel}, headers=AUTH
    ).json()
    assert body["status"] == "needs_review"
    assert any(issue["code"] == "CONTAINER_XML_MALFORMED" for issue in body["issues"])


def test_validate_malformed_opf_fails(client, install_epub):
    rel = install_epub(builders.build_malformed_opf())
    body = client.post(
        "/internal/v1/epub/validate", json={"asset_ref": "a", "relative_path": rel}, headers=AUTH
    ).json()
    assert body["status"] == "failed"
    assert any(issue["code"] == "EPUB_OPF_UNREADABLE" for issue in body["issues"])


# --- full pipeline -----------------------------------------------------------


def test_pipeline_e2e(client, install_epub, data_root):
    rel = install_epub(builders.build_epub3())
    payload = _stage_payload(rel)

    validate = client.post(
        "/internal/v1/epub/validate", json={"asset_ref": "a", "relative_path": rel}, headers=AUTH
    ).json()
    assert validate["status"] == "passed"

    parse = client.post("/internal/v1/epub/parse", json=payload, headers=AUTH).json()
    assert parse["status"] == "passed"
    assert parse["result"]["metadata"]["title"] == "The Synthetic Book"

    normalize = client.post("/internal/v1/epub/normalize", json=payload, headers=AUTH).json()
    assert normalize["status"] == "passed"
    assert normalize["result"]["spine_documents"] == 3
    assert normalize["result"]["nodes"] > 0

    structure = client.post("/internal/v1/epub/structure", json=payload, headers=AUTH).json()
    assert structure["status"] == "passed"
    assert structure["result"]["toc_summary"]["source"] == "nav"
    assert structure["result"]["toc_summary"]["entries"] == 4
    assert structure["result"]["toc_summary"]["resolved"] == 4
    assert len(structure["result"]["spine"]) == 3

    artifact_dir = data_root / "artifacts/book1"
    assert (artifact_dir / "metadata.json").is_file()
    for index in range(3):
        assert (artifact_dir / f"spine/{index:04d}.jsonl").is_file()
    assert (artifact_dir / "structure.json").is_file()
    assert (artifact_dir / "manifest.json").is_file()

    # atomic writes leave no temp files behind
    leftovers = [p for p in artifact_dir.rglob("*") if ".tmp-" in p.name]
    assert leftovers == []

    structure_doc = json.loads((artifact_dir / "structure.json").read_text())
    assert structure_doc["content_sha256"] == structure["result"]["content_sha256"]
    assert structure_doc["fingerprint_version"] == "1"

    manifest = json.loads((artifact_dir / "manifest.json").read_text())
    assert manifest["asset_ref"] == "asset-0001"
    assert manifest["pipeline_version"] == "pv-1"
    assert manifest["source_sha256"] == "0" * 64
    assert set(manifest["stages"].keys()) == {"parse", "normalize", "structure"}
    assert manifest["stages"]["parse"]["handler_version"] == "1.0.0"
    assert manifest["stages"]["normalize"]["handler_version"] == "1.1.0"
    assert manifest["stages"]["structure"]["handler_version"] == "1.1.0"
    for stage in manifest["stages"].values():
        assert "completed_at" in stage
    output_paths = {entry["path"] for entry in manifest["outputs"]}
    assert "metadata.json" in output_paths
    assert "structure.json" in output_paths
    assert "spine/0000.jsonl" in output_paths
    assert "sanitized/0000.xhtml" in output_paths
    assert "canonical.txt" in output_paths
    for entry in manifest["outputs"]:
        assert len(entry["sha256"]) == 64
        assert entry["bytes"] == (artifact_dir / entry["path"]).stat().st_size

    metadata_doc = json.loads((artifact_dir / "metadata.json").read_text())
    assert metadata_doc["provenance"]["source"] == "epub_opf"
    assert metadata_doc["provenance"]["opf_path"] == "OEBPS/content.opf"
    assert metadata_doc["normalized"]["title"] == "The Synthetic Book"
    assert isinstance(metadata_doc["raw"], list)


def test_normalize_twice_is_byte_identical(client, install_epub, data_root):
    rel = install_epub(builders.build_epub3())
    payload = _stage_payload(rel)

    assert client.post("/internal/v1/epub/normalize", json=payload, headers=AUTH).json()["status"] == "passed"
    first = [(data_root / "artifacts/book1" / f"spine/{i:04d}.jsonl").read_bytes() for i in range(3)]
    assert client.post("/internal/v1/epub/normalize", json=payload, headers=AUTH).json()["status"] == "passed"
    second = [(data_root / "artifacts/book1" / f"spine/{i:04d}.jsonl").read_bytes() for i in range(3)]
    assert first == second


def test_structure_without_normalize_fails(client, install_epub):
    rel = install_epub(builders.build_epub3())
    payload = _stage_payload(rel, artifact_dir="artifacts/empty")
    body = client.post("/internal/v1/epub/structure", json=payload, headers=AUTH).json()
    assert body["status"] == "failed"
    assert any(issue["code"] == "SPINE_ARTIFACTS_MISSING" for issue in body["issues"])


def test_epub2_pipeline(client, install_epub, data_root):
    rel = install_epub(builders.build_epub2(), "assets/book2.epub")
    payload = _stage_payload(rel, artifact_dir="artifacts/book2")

    parse = client.post("/internal/v1/epub/parse", json=payload, headers=AUTH).json()
    assert parse["status"] == "passed"
    isbn = parse["result"]["metadata"]["identifiers"][0]
    assert isbn["scheme"] == "isbn10"
    assert isbn["isbn13"] == builders.ISBN13

    assert client.post("/internal/v1/epub/normalize", json=payload, headers=AUTH).json()["status"] == "passed"
    structure = client.post("/internal/v1/epub/structure", json=payload, headers=AUTH).json()
    assert structure["status"] == "passed"
    assert structure["result"]["toc_summary"]["source"] == "ncx"
    assert structure["result"]["toc_summary"]["entries"] == 3


def test_health_endpoints_unaffected(client):
    response = client.get("/health/live")
    assert response.status_code == 200
    assert response.json() == {"status": "ok", "service": "mnemosyne-ai-worker"}
