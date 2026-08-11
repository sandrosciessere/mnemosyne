import pytest
from fastapi.testclient import TestClient

TOKEN = "test-internal-token"
AUTH = {"X-Mnemosyne-Internal-Token": TOKEN}


@pytest.fixture
def data_root(tmp_path, monkeypatch):
    root = tmp_path / "data"
    root.mkdir()
    monkeypatch.setenv("WORKER_DATA_PATH", str(root))
    monkeypatch.setenv("MNEMOSYNE_INTERNAL_TOKEN", TOKEN)
    return root


@pytest.fixture
def client(data_root):
    from app.main import app

    return TestClient(app)


@pytest.fixture
def install_epub(data_root):
    """Write EPUB bytes under the data root; returns the relative path."""

    def _install(data: bytes, relative: str = "assets/book.epub") -> str:
        target = data_root / relative
        target.parent.mkdir(parents=True, exist_ok=True)
        target.write_bytes(data)
        return relative

    return _install
