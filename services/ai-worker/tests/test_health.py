import os

from fastapi.testclient import TestClient


def make_client(tmp_path, checks: str) -> TestClient:
    os.environ["WORKER_READINESS_CHECKS"] = checks
    os.environ["WORKER_DATA_PATH"] = str(tmp_path)
    os.environ["OLLAMA_BASE_URL"] = "http://127.0.0.1:1"  # unreachable on purpose

    from app.main import app

    return TestClient(app)


def test_live(tmp_path):
    client = make_client(tmp_path, checks="storage")
    response = client.get("/health/live")

    assert response.status_code == 200
    assert response.json() == {"status": "ok", "service": "mnemosyne-ai-worker"}


def test_ready_with_storage_check(tmp_path):
    client = make_client(tmp_path, checks="storage")
    response = client.get("/health/ready")
    body = response.json()

    assert response.status_code == 200
    assert body["status"] == "ok"
    assert body["checks"] == {"storage": "ok"}
    assert body["ollama"] in {"available", "unavailable"}


def test_ready_fails_when_storage_missing(tmp_path):
    client = make_client(tmp_path, checks="storage")
    os.environ["WORKER_DATA_PATH"] = str(tmp_path / "does-not-exist")

    response = client.get("/health/ready")

    assert response.status_code == 503
    assert response.json()["status"] == "degraded"
    assert response.json()["checks"]["storage"] == "failed"
