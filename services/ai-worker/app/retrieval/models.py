"""Registry of the local retrieval models (pinned revisions).

Model files are NOT committed to git; they live in the Hugging Face cache
under ``HF_HOME`` (default ``/data/models/hf``) and are provisioned with
``python -m app.retrieval.provision``. Revisions are exact commit hashes
resolved from Hugging Face at provisioning time and hardcoded here so the
worker always serves a known model identity. Book text never leaves the
server: inference is strictly local and CPU-only.
"""

from dataclasses import dataclass

KIND_EMBEDDING = "embedding"
KIND_RERANKER = "reranker"


@dataclass(frozen=True)
class ModelSpec:
    model_key: str
    hf_id: str
    revision: str  # full commit hash, pinned
    kind: str  # KIND_EMBEDDING | KIND_RERANKER
    dims: int | None  # embedding dimensionality (None for rerankers)
    license: str
    notes: str
    # Prefixes applied by the worker per input_type ("query"/"passage").
    # Callers always send raw text; E5-style models need these prefixes.
    input_prefixes: dict[str, str] | None = None


REGISTRY: dict[str, ModelSpec] = {
    "e5-small-v1": ModelSpec(
        model_key="e5-small-v1",
        hf_id="intfloat/multilingual-e5-small",
        revision="614241f622f53c4eeff9890bdc4f31cfecc418b3",
        kind=KIND_EMBEDDING,
        dims=384,
        license="MIT",
        notes=(
            "Multilingual E5 small; cosine metric over L2-normalized vectors; "
            "tokenizer max_seq_length 512 — longer inputs are truncated by the model. "
            "E5 usage prefixes are applied server-side per input_type."
        ),
        input_prefixes={"query": "query: ", "passage": "passage: "},
    ),
    "mmarco-mini-v1": ModelSpec(
        model_key="mmarco-mini-v1",
        hf_id="cross-encoder/mmarco-mMiniLMv2-L12-H384-v1",
        revision="1427fd652930e4ba29e8149678df786c240d8825",
        kind=KIND_RERANKER,
        dims=None,
        license="Apache-2.0",
        notes=(
            "Multilingual mMARCO cross-encoder reranker; scores are uncalibrated, "
            "higher is better."
        ),
    ),
}


def get_spec(model_key: str) -> ModelSpec | None:
    return REGISTRY.get(model_key)
