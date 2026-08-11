"""Fast retrieval-API tests: the model layer is faked, no ML imports."""

import pytest

from app.retrieval import loader
from app.retrieval.models import REGISTRY
from tests.conftest import AUTH

EMBED_URL = "/internal/v1/retrieval/embed"
RERANK_URL = "/internal/v1/retrieval/rerank"
MODELS_URL = "/internal/v1/retrieval/models"

DIMS = 384

_REAL_IS_CACHED = loader.is_cached  # kept before any monkeypatching


class FakeSentenceTransformer:
    def __init__(self, rows=None):
        self.calls = []
        self.rows = rows  # optional override: list of vectors to return

    def encode(self, texts, batch_size=None, normalize_embeddings=None, convert_to_numpy=None, show_progress_bar=None):
        self.calls.append({"texts": list(texts), "batch_size": batch_size, "normalize": normalize_embeddings})
        if self.rows is not None:
            return self.rows
        unit = 1.0 / (DIMS**0.5)
        return [[unit] * DIMS for _ in texts]


class FakeCrossEncoder:
    def __init__(self, scores=None):
        self.calls = []
        self.scores = scores

    def predict(self, pairs, batch_size=None, show_progress_bar=None):
        self.calls.append({"pairs": list(pairs), "batch_size": batch_size})
        if self.scores is not None:
            return self.scores
        return [float(len(pairs) - index) for index in range(len(pairs))]


@pytest.fixture(autouse=True)
def retrieval_env(tmp_path, monkeypatch):
    """Isolated empty HF cache, downloads disabled, fresh loader state."""
    monkeypatch.setenv("HF_HOME", str(tmp_path / "hf"))
    monkeypatch.delenv("WORKER_ALLOW_MODEL_DOWNLOAD", raising=False)
    loader._MODELS.clear()
    yield
    loader._MODELS.clear()


@pytest.fixture
def fake_models(monkeypatch):
    """Install fakes into the loader; returns them for inspection."""
    embedder = FakeSentenceTransformer()
    reranker = FakeCrossEncoder()
    monkeypatch.setattr(loader, "is_cached", lambda spec: True)
    monkeypatch.setattr(loader, "_load_embedder", lambda spec: embedder)
    monkeypatch.setattr(loader, "_load_reranker", lambda spec: reranker)
    return {"embedder": embedder, "reranker": reranker}


def embed_payload(**overrides) -> dict:
    payload = {
        "model_key": "e5-small-v1",
        "input_type": "query",
        "texts": ["il gatto dorme"],
        "correlation_id": "corr-1",
    }
    payload.update(overrides)
    return payload


def rerank_payload(**overrides) -> dict:
    payload = {
        "model_key": "mmarco-mini-v1",
        "query": "di che colore è il gatto?",
        "candidates": [
            {"id": "c1", "text": "il gatto è nero"},
            {"id": "c2", "text": "domani piove"},
        ],
        "correlation_id": "corr-2",
    }
    payload.update(overrides)
    return payload


# --- auth --------------------------------------------------------------------


def test_embed_missing_token_is_401(client):
    assert client.post(EMBED_URL, json=embed_payload()).status_code == 401


def test_embed_wrong_token_is_401(client):
    response = client.post(EMBED_URL, json=embed_payload(), headers={"X-Mnemosyne-Internal-Token": "nope"})
    assert response.status_code == 401


def test_rerank_missing_token_is_401(client):
    assert client.post(RERANK_URL, json=rerank_payload()).status_code == 401


def test_models_missing_token_is_401(client):
    assert client.get(MODELS_URL).status_code == 401


def test_unset_token_is_503(client, monkeypatch):
    monkeypatch.delenv("MNEMOSYNE_INTERNAL_TOKEN")
    response = client.post(EMBED_URL, json=embed_payload(), headers=AUTH)
    assert response.status_code == 503
    assert response.json()["detail"]["code"] == "TOKEN_NOT_CONFIGURED"


# --- request validation (422 before any model is touched) --------------------


def test_embed_empty_texts_list_is_422(client):
    assert client.post(EMBED_URL, json=embed_payload(texts=[]), headers=AUTH).status_code == 422


def test_embed_too_many_texts_is_422(client):
    assert client.post(EMBED_URL, json=embed_payload(texts=["x"] * 65), headers=AUTH).status_code == 422


def test_embed_empty_string_is_422(client):
    assert client.post(EMBED_URL, json=embed_payload(texts=["ok", "  "]), headers=AUTH).status_code == 422


def test_embed_oversized_text_is_422(client):
    assert client.post(EMBED_URL, json=embed_payload(texts=["x" * 8001]), headers=AUTH).status_code == 422


def test_embed_bad_input_type_is_422(client):
    assert client.post(EMBED_URL, json=embed_payload(input_type="document"), headers=AUTH).status_code == 422


def test_embed_unknown_model_key_is_422(client):
    response = client.post(EMBED_URL, json=embed_payload(model_key="nope-v1"), headers=AUTH)
    assert response.status_code == 422
    assert response.json()["detail"]["code"] == "MODEL_UNKNOWN"


def test_embed_with_reranker_key_is_422(client):
    response = client.post(EMBED_URL, json=embed_payload(model_key="mmarco-mini-v1"), headers=AUTH)
    assert response.status_code == 422
    assert response.json()["detail"]["code"] == "MODEL_KIND_MISMATCH"


def test_rerank_with_embedder_key_is_422(client):
    response = client.post(RERANK_URL, json=rerank_payload(model_key="e5-small-v1"), headers=AUTH)
    assert response.status_code == 422
    assert response.json()["detail"]["code"] == "MODEL_KIND_MISMATCH"


def test_rerank_oversized_query_is_422(client):
    assert client.post(RERANK_URL, json=rerank_payload(query="q" * 2001), headers=AUTH).status_code == 422


def test_rerank_empty_query_is_422(client):
    assert client.post(RERANK_URL, json=rerank_payload(query="  "), headers=AUTH).status_code == 422


def test_rerank_no_candidates_is_422(client):
    assert client.post(RERANK_URL, json=rerank_payload(candidates=[]), headers=AUTH).status_code == 422


def test_rerank_too_many_candidates_is_422(client):
    candidates = [{"id": f"c{i}", "text": "t"} for i in range(65)]
    assert client.post(RERANK_URL, json=rerank_payload(candidates=candidates), headers=AUTH).status_code == 422


def test_rerank_oversized_candidate_text_is_422(client):
    candidates = [{"id": "c1", "text": "x" * 8001}]
    assert client.post(RERANK_URL, json=rerank_payload(candidates=candidates), headers=AUTH).status_code == 422


def test_rerank_empty_candidate_id_is_422(client):
    candidates = [{"id": "", "text": "ok"}]
    assert client.post(RERANK_URL, json=rerank_payload(candidates=candidates), headers=AUTH).status_code == 422


# --- provisioning gate -------------------------------------------------------


def test_embed_unprovisioned_model_is_503(client):
    response = client.post(EMBED_URL, json=embed_payload(), headers=AUTH)
    assert response.status_code == 503
    assert response.json()["detail"]["code"] == "MODEL_NOT_PROVISIONED"


def test_rerank_unprovisioned_model_is_503(client):
    response = client.post(RERANK_URL, json=rerank_payload(), headers=AUTH)
    assert response.status_code == 503
    assert response.json()["detail"]["code"] == "MODEL_NOT_PROVISIONED"


# --- embed behavior ----------------------------------------------------------


def test_embed_happy_path_and_query_prefix(client, fake_models):
    response = client.post(EMBED_URL, json=embed_payload(texts=["il gatto dorme", "altro testo"]), headers=AUTH)
    assert response.status_code == 200
    body = response.json()
    assert body["model_key"] == "e5-small-v1"
    assert body["dims"] == DIMS
    assert body["input_type"] == "query"
    assert body["model_identity"] == {
        "hf_id": "intfloat/multilingual-e5-small",
        "revision": REGISTRY["e5-small-v1"].revision,
        "dims": DIMS,
        "normalized": True,
        "metric": "cosine",
    }
    assert len(body["vectors"]) == 2
    assert all(len(vector) == DIMS for vector in body["vectors"])
    assert isinstance(body["duration_ms"], int)
    call = fake_models["embedder"].calls[0]
    assert call["texts"] == ["query: il gatto dorme", "query: altro testo"]
    assert call["normalize"] is True


def test_embed_passage_prefix(client, fake_models):
    response = client.post(EMBED_URL, json=embed_payload(input_type="passage"), headers=AUTH)
    assert response.status_code == 200
    assert fake_models["embedder"].calls[0]["texts"] == ["passage: il gatto dorme"]


def test_embed_batch_size_env(client, fake_models, monkeypatch):
    monkeypatch.setenv("WORKER_EMBED_BATCH_SIZE", "4")
    client.post(EMBED_URL, json=embed_payload(), headers=AUTH)
    assert fake_models["embedder"].calls[0]["batch_size"] == 4


def test_embed_default_batch_size_is_16(client, fake_models):
    client.post(EMBED_URL, json=embed_payload(), headers=AUTH)
    assert fake_models["embedder"].calls[0]["batch_size"] == 16


def test_embed_nan_vector_is_500(client, fake_models):
    fake_models["embedder"].rows = [[float("nan")] * DIMS]
    response = client.post(EMBED_URL, json=embed_payload(), headers=AUTH)
    assert response.status_code == 500
    assert response.json()["detail"]["code"] == "INTERNAL_ERROR"


def test_embed_wrong_dims_is_500(client, fake_models):
    fake_models["embedder"].rows = [[0.1] * (DIMS - 1)]
    response = client.post(EMBED_URL, json=embed_payload(), headers=AUTH)
    assert response.status_code == 500
    assert response.json()["detail"]["code"] == "INTERNAL_ERROR"


def test_embed_model_loads_once(client, fake_models, monkeypatch):
    load_calls = []
    monkeypatch.setattr(
        loader, "_load_embedder", lambda spec: load_calls.append(spec.model_key) or fake_models["embedder"]
    )
    client.post(EMBED_URL, json=embed_payload(), headers=AUTH)
    client.post(EMBED_URL, json=embed_payload(), headers=AUTH)
    assert load_calls == ["e5-small-v1"]


# --- rerank behavior ---------------------------------------------------------


def test_rerank_happy_path_preserves_ids_and_order(client, fake_models):
    fake_models["reranker"].scores = [-1.5, 7.25]
    response = client.post(RERANK_URL, json=rerank_payload(), headers=AUTH)
    assert response.status_code == 200
    body = response.json()
    assert body["model_key"] == "mmarco-mini-v1"
    assert body["model_identity"] == {
        "hf_id": "cross-encoder/mmarco-mMiniLMv2-L12-H384-v1",
        "revision": REGISTRY["mmarco-mini-v1"].revision,
    }
    # ids 1:1 with input, input order preserved even when scores rank otherwise
    assert body["scores"] == [{"id": "c1", "score": -1.5}, {"id": "c2", "score": 7.25}]
    pairs = fake_models["reranker"].calls[0]["pairs"]
    assert pairs == [
        ("di che colore è il gatto?", "il gatto è nero"),
        ("di che colore è il gatto?", "domani piove"),
    ]
    assert fake_models["reranker"].calls[0]["batch_size"] == 16


def test_rerank_nan_score_is_500(client, fake_models):
    fake_models["reranker"].scores = [float("inf"), 1.0]
    response = client.post(RERANK_URL, json=rerank_payload(), headers=AUTH)
    assert response.status_code == 500
    assert response.json()["detail"]["code"] == "INTERNAL_ERROR"


def test_rerank_score_count_mismatch_is_500(client, fake_models):
    fake_models["reranker"].scores = [1.0]
    response = client.post(RERANK_URL, json=rerank_payload(), headers=AUTH)
    assert response.status_code == 500
    assert response.json()["detail"]["code"] == "INTERNAL_ERROR"


# --- models listing ----------------------------------------------------------


def test_models_listing_uncached(client):
    response = client.get(MODELS_URL, headers=AUTH)
    assert response.status_code == 200
    models = {entry["model_key"]: entry for entry in response.json()["models"]}
    assert set(models) == {"e5-small-v1", "mmarco-mini-v1"}
    e5 = models["e5-small-v1"]
    assert e5["kind"] == "embedding"
    assert e5["hf_id"] == "intfloat/multilingual-e5-small"
    assert e5["revision"] == REGISTRY["e5-small-v1"].revision
    assert e5["dims"] == DIMS
    assert e5["cached"] is False
    assert e5["loaded"] is False
    reranker = models["mmarco-mini-v1"]
    assert reranker["kind"] == "reranker"
    assert "dims" not in reranker
    assert reranker["cached"] is False


def test_models_listing_cached_and_loaded(client, tmp_path, fake_models, monkeypatch):
    # fake_models patches is_cached; restore the real scan for this test.
    monkeypatch.setattr(loader, "is_cached", _REAL_IS_CACHED)

    spec = REGISTRY["e5-small-v1"]
    snapshot = tmp_path / "hf" / "hub" / "models--intfloat--multilingual-e5-small" / "snapshots" / spec.revision
    snapshot.mkdir(parents=True)
    (snapshot / "config.json").write_text("{}")
    (snapshot / "model.safetensors").write_text("fake")

    client.post(EMBED_URL, json=embed_payload(), headers=AUTH)  # loads the fake embedder
    response = client.get(MODELS_URL, headers=AUTH)
    models = {entry["model_key"]: entry for entry in response.json()["models"]}
    assert models["e5-small-v1"]["cached"] is True
    assert models["e5-small-v1"]["loaded"] is True
    assert models["mmarco-mini-v1"]["cached"] is False
    assert models["mmarco-mini-v1"]["loaded"] is False
