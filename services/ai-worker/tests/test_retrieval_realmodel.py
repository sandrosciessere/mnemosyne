"""Real-model retrieval tests (opt-in).

Run only on the server with the models already provisioned::

    REALMODEL=1 HF_HOME=/path/to/hf pytest -q -m realmodel

Never runs in the default suite: the marker is excluded and the module is
additionally skipped unless REALMODEL=1. No network is needed when the
pinned revisions are in the local cache. Texts are synthetic — no book
content.
"""

import math
import os
import time

import pytest

from tests.conftest import AUTH

pytestmark = [
    pytest.mark.realmodel,
    pytest.mark.skipif(os.environ.get("REALMODEL") != "1", reason="set REALMODEL=1 to run real-model tests"),
]

EMBED_URL = "/internal/v1/retrieval/embed"
RERANK_URL = "/internal/v1/retrieval/rerank"

CAT_PASSAGE = "Il gatto è un piccolo felino domestico; ama dormire al sole e fare le fusa."
UNRELATED_PASSAGE = "La fattura elettronica deve essere trasmessa al sistema di interscambio entro dodici giorni."


def _embed(client, texts, input_type):
    response = client.post(
        EMBED_URL,
        json={"model_key": "e5-small-v1", "input_type": input_type, "texts": texts},
        headers=AUTH,
    )
    assert response.status_code == 200, response.text
    return response.json()


def _dot(a, b):
    return sum(x * y for x, y in zip(a, b, strict=True))


def test_embed_dims_and_normalization(client):
    body = _embed(client, ["il gatto dorme sul divano"], "passage")
    assert body["dims"] == 384
    assert body["model_identity"]["dims"] == 384
    (vector,) = body["vectors"]
    assert len(vector) == 384
    assert all(math.isfinite(value) for value in vector)
    norm = math.sqrt(_dot(vector, vector))
    assert abs(norm - 1.0) < 1e-3  # normalize_embeddings=True


def test_embed_cosine_relevance_italian(client):
    (query,) = _embed(client, ["gatto"], "query")["vectors"]
    relevant, unrelated = _embed(client, [CAT_PASSAGE, UNRELATED_PASSAGE], "passage")["vectors"]
    assert _dot(query, relevant) > _dot(query, unrelated)


def test_rerank_relevance_and_finite_scores(client):
    response = client.post(
        RERANK_URL,
        json={
            "model_key": "mmarco-mini-v1",
            "query": "Di che colore è il gatto?",
            "candidates": [
                {"id": "relevant", "text": "Il gatto di Maria è nero e dorme sempre in cucina."},
                {"id": "irrelevant", "text": "Domani è prevista pioggia su tutta la regione."},
            ],
        },
        headers=AUTH,
    )
    assert response.status_code == 200, response.text
    scores = {entry["id"]: entry["score"] for entry in response.json()["scores"]}
    assert set(scores) == {"relevant", "irrelevant"}
    assert all(math.isfinite(score) for score in scores.values())
    assert scores["relevant"] > scores["irrelevant"]


def test_endpoint_latency_sane(client):
    texts = [f"Frase sintetica numero {i}: il sistema archivia documenti e libri digitali." for i in range(16)]
    _embed(client, ["warmup"], "passage")  # ensure the model is loaded

    started = time.monotonic()
    body = _embed(client, texts, "passage")
    embed_seconds = time.monotonic() - started
    assert len(body["vectors"]) == 16
    assert embed_seconds < 30, f"16-text embed took {embed_seconds:.1f}s"
    print(f"\nembed 16 texts: {embed_seconds * 1000:.0f} ms ({16 / embed_seconds:.1f} texts/s)")

    candidates = [{"id": f"c{i}", "text": text} for i, text in enumerate(texts)]
    payload = {"model_key": "mmarco-mini-v1", "query": "come vengono archiviati i libri?", "candidates": candidates}
    client.post(RERANK_URL, json=payload, headers=AUTH)  # warmup/load
    started = time.monotonic()
    response = client.post(RERANK_URL, json=payload, headers=AUTH)
    rerank_seconds = time.monotonic() - started
    assert response.status_code == 200, response.text
    assert len(response.json()["scores"]) == 16
    assert rerank_seconds < 60, f"16-candidate rerank took {rerank_seconds:.1f}s"
    print(f"rerank 16 candidates: {rerank_seconds * 1000:.0f} ms")
