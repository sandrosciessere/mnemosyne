"""Internal retrieval API (/internal/v1/retrieval/*): local embed + rerank.

Same auth as the EPUB stages (``X-Mnemosyne-Internal-Token``). All model
inference is local and CPU-only; book text never leaves the server. Models
load lazily (once per process) from the HF cache; when the pinned files
are absent and downloads are disabled the endpoints answer 503
``MODEL_NOT_PROVISIONED`` — Laravel treats that as retryable-transient so
an operator can provision and retry.

Notes:
- e5 embeddings: the worker applies the E5 prefixes (``query: `` /
  ``passage: ``) based on ``input_type``; callers send raw text. Inputs
  longer than the model's 512-token max_seq_length are truncated by the
  model tokenizer.
- rerank scores: raw model outputs, finite floats, higher is better,
  uncalibrated; response order and ids mirror the input 1:1.
"""

import logging
import math
import time
from typing import Literal

from fastapi import APIRouter, Depends, HTTPException
from pydantic import BaseModel, Field, field_validator

from app import config
from app.retrieval import loader
from app.retrieval.models import KIND_EMBEDDING, KIND_RERANKER, REGISTRY, ModelSpec, get_spec
from app.security import require_internal_token

logger = logging.getLogger("mnemosyne.retrieval_v1")

router = APIRouter(prefix="/internal/v1/retrieval", dependencies=[Depends(require_internal_token)])

MAX_TEXTS = 64
MAX_TEXT_CHARS = 8000
MAX_QUERY_CHARS = 2000
MAX_CANDIDATES = 64
RERANK_BATCH_SIZE = 16


class EmbedRequest(BaseModel):
    model_key: str
    input_type: Literal["query", "passage"]
    texts: list[str] = Field(min_length=1, max_length=MAX_TEXTS)
    correlation_id: str | None = None

    @field_validator("texts")
    @classmethod
    def _check_texts(cls, texts: list[str]) -> list[str]:
        for index, text in enumerate(texts):
            if not text.strip():
                raise ValueError(f"texts[{index}] must not be empty")
            if len(text) > MAX_TEXT_CHARS:
                raise ValueError(f"texts[{index}] exceeds {MAX_TEXT_CHARS} characters")
        return texts


class RerankCandidate(BaseModel):
    id: str = Field(min_length=1)
    text: str = Field(max_length=MAX_TEXT_CHARS)

    @field_validator("text")
    @classmethod
    def _check_text(cls, text: str) -> str:
        if not text.strip():
            raise ValueError("candidate text must not be empty")
        return text


class RerankRequest(BaseModel):
    model_key: str
    query: str = Field(max_length=MAX_QUERY_CHARS)
    candidates: list[RerankCandidate] = Field(min_length=1, max_length=MAX_CANDIDATES)
    correlation_id: str | None = None

    @field_validator("query")
    @classmethod
    def _check_query(cls, query: str) -> str:
        if not query.strip():
            raise ValueError("query must not be empty")
        return query


def _http_error(status_code: int, code: str, message: str) -> HTTPException:
    return HTTPException(status_code=status_code, detail={"code": code, "message": message})


def _require_spec(model_key: str, kind: str) -> ModelSpec:
    spec = get_spec(model_key)
    if spec is None:
        raise _http_error(422, "MODEL_UNKNOWN", f"unknown model_key '{model_key}'")
    if spec.kind != kind:
        raise _http_error(422, "MODEL_KIND_MISMATCH", f"model '{model_key}' is a {spec.kind}, expected {kind}")
    return spec


def _get_model(spec: ModelSpec):
    try:
        return loader.get_model(spec)
    except loader.ModelNotProvisioned as exc:
        raise _http_error(503, "MODEL_NOT_PROVISIONED", str(exc)) from exc
    except HTTPException:
        raise
    except Exception as exc:
        logger.exception("failed to load model %s", spec.model_key)
        raise _http_error(500, "INTERNAL_ERROR", "failed to load model") from exc


def _as_float_rows(raw) -> list[list[float]]:
    if hasattr(raw, "tolist"):
        raw = raw.tolist()
    rows = []
    for row in raw:
        if hasattr(row, "tolist"):
            row = row.tolist()
        rows.append([float(value) for value in row])
    return rows


def _as_floats(raw) -> list[float]:
    if hasattr(raw, "tolist"):
        raw = raw.tolist()
    return [float(value) for value in raw]


@router.post("/embed")
def retrieval_embed(req: EmbedRequest) -> dict:
    started = time.monotonic()
    spec = _require_spec(req.model_key, KIND_EMBEDDING)
    model = _get_model(spec)

    prefix = (spec.input_prefixes or {}).get(req.input_type, "")
    texts = [prefix + text for text in req.texts]

    try:
        raw = model.encode(
            texts,
            batch_size=config.get_embed_batch_size(),
            normalize_embeddings=True,
            convert_to_numpy=True,
            show_progress_bar=False,
        )
        vectors = _as_float_rows(raw)
    except Exception as exc:
        logger.exception("embedding failed for model %s", spec.model_key)
        raise _http_error(500, "INTERNAL_ERROR", "embedding failed") from exc

    # Never emit NaN/Inf or wrongly-sized vectors: fail hard instead.
    if len(vectors) != len(texts) or any(
        len(vector) != spec.dims or not all(math.isfinite(value) for value in vector) for vector in vectors
    ):
        logger.error("model %s produced invalid vectors", spec.model_key)
        raise _http_error(500, "INTERNAL_ERROR", "model produced invalid vectors")

    return {
        "model_key": spec.model_key,
        "model_identity": {
            "hf_id": spec.hf_id,
            "revision": spec.revision,
            "dims": spec.dims,
            "normalized": True,
            "metric": "cosine",
        },
        "input_type": req.input_type,
        "vectors": vectors,
        "dims": spec.dims,
        "duration_ms": int((time.monotonic() - started) * 1000),
    }


@router.post("/rerank")
def retrieval_rerank(req: RerankRequest) -> dict:
    started = time.monotonic()
    spec = _require_spec(req.model_key, KIND_RERANKER)
    model = _get_model(spec)

    pairs = [(req.query, candidate.text) for candidate in req.candidates]
    try:
        raw = model.predict(pairs, batch_size=RERANK_BATCH_SIZE, show_progress_bar=False)
        scores = _as_floats(raw)
    except Exception as exc:
        logger.exception("rerank failed for model %s", spec.model_key)
        raise _http_error(500, "INTERNAL_ERROR", "rerank failed") from exc

    if len(scores) != len(req.candidates) or not all(math.isfinite(score) for score in scores):
        logger.error("model %s produced invalid scores", spec.model_key)
        raise _http_error(500, "INTERNAL_ERROR", "model produced invalid scores")

    return {
        "model_key": spec.model_key,
        "model_identity": {"hf_id": spec.hf_id, "revision": spec.revision},
        "scores": [
            {"id": candidate.id, "score": score}
            for candidate, score in zip(req.candidates, scores, strict=True)
        ],
        "duration_ms": int((time.monotonic() - started) * 1000),
    }


@router.get("/models")
def retrieval_models() -> dict:
    models = []
    for key in sorted(REGISTRY):
        spec = REGISTRY[key]
        entry: dict = {
            "model_key": spec.model_key,
            "kind": spec.kind,
            "hf_id": spec.hf_id,
            "revision": spec.revision,
            "cached": loader.is_cached(spec),
            "loaded": loader.is_loaded(spec.model_key),
        }
        if spec.dims is not None:
            entry["dims"] = spec.dims
        models.append(entry)
    return {"models": models}
