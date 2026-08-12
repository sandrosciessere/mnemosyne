"""Lazy, thread-safe loading of the local retrieval models.

Heavy ML imports (torch / sentence-transformers) happen ONLY inside the
load functions, never at module import time: the API process must start
and the offline error paths must work in an image without the ML stack.

Offline policy: unless ``WORKER_ALLOW_MODEL_DOWNLOAD=1`` the loader sets
``HF_HUB_OFFLINE=1``/``TRANSFORMERS_OFFLINE=1`` before touching the ML
stack, and refuses to load a model whose pinned revision is not already in
the local cache (:class:`ModelNotProvisioned` -> HTTP 503). Book text and
model traffic never leave the server in offline mode.
"""

import os
import threading

from app import config
from app.retrieval.models import ModelSpec

# One weight file must be present in the snapshot for it to count as cached.
_WEIGHT_FILES = ("model.safetensors", "pytorch_model.bin")

_LOCK = threading.Lock()
_MODELS: dict[str, object] = {}  # model_key -> loaded model (process lifetime)


class ModelNotProvisioned(Exception):
    """The pinned model files are absent locally and downloads are disabled."""

    def __init__(self, spec: ModelSpec):
        self.spec = spec
        super().__init__(
            f"model '{spec.model_key}' ({spec.hf_id}@{spec.revision}) is not in the "
            "local cache and WORKER_ALLOW_MODEL_DOWNLOAD is disabled; provision it "
            "with: python -m app.retrieval.provision --model-key " + spec.model_key
        )


def hub_cache_dir() -> str:
    """The huggingface_hub cache directory (``$HF_HOME/hub``)."""
    return os.path.join(str(config.get_hf_home()), "hub")


def snapshot_dir(spec: ModelSpec) -> str:
    """Cache snapshot directory for the pinned revision (pure path math)."""
    repo_dir = "models--" + spec.hf_id.replace("/", "--")
    return os.path.join(hub_cache_dir(), repo_dir, "snapshots", spec.revision)


def is_cached(spec: ModelSpec) -> bool:
    """Filesystem-only check (no network, no ML imports, no downloads)."""
    snapshot = snapshot_dir(spec)
    if not os.path.isfile(os.path.join(snapshot, "config.json")):
        return False
    return any(os.path.isfile(os.path.join(snapshot, name)) for name in _WEIGHT_FILES)


def is_loaded(model_key: str) -> bool:
    return model_key in _MODELS


def get_model(spec: ModelSpec):
    """Return the loaded model for ``spec``, loading it once per process."""
    model = _MODELS.get(spec.model_key)
    if model is not None:
        return model
    with _LOCK:
        model = _MODELS.get(spec.model_key)
        if model is None:
            if not config.allow_model_download() and not is_cached(spec):
                raise ModelNotProvisioned(spec)
            factory = _load_embedder if spec.kind == "embedding" else _load_reranker
            model = factory(spec)
            _MODELS[spec.model_key] = model
    return model


def _prepare_ml_env() -> None:
    """Fix HF_HOME and enforce offline mode BEFORE the ML stack is imported."""
    os.environ["HF_HOME"] = str(config.get_hf_home())
    if not config.allow_model_download():
        os.environ["HF_HUB_OFFLINE"] = "1"
        os.environ["TRANSFORMERS_OFFLINE"] = "1"


def _load_embedder(spec: ModelSpec):
    _prepare_ml_env()
    from sentence_transformers import SentenceTransformer  # heavy: lazy import

    return SentenceTransformer(spec.hf_id, revision=spec.revision, device="cpu")


def _load_reranker(spec: ModelSpec):
    _prepare_ml_env()
    from sentence_transformers import CrossEncoder  # heavy: lazy import

    return CrossEncoder(spec.hf_id, revision=spec.revision, device="cpu")
