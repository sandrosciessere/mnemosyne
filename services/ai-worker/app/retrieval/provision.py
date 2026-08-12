"""Provision the pinned retrieval models into the local HF cache.

Usage (inside the ai-worker container, network required)::

    python -m app.retrieval.provision --all
    python -m app.retrieval.provision --model-key e5-small-v1

Downloads go to ``$HF_HOME/hub`` (default ``/data/models/hf/hub``) at the
EXACT revisions pinned in :mod:`app.retrieval.models`. ONNX/OpenVINO
exports and duplicate ``pytorch_model.bin`` weights are skipped (the
worker loads ``model.safetensors`` with plain torch on CPU).
"""

import argparse
import os
import sys

from app.retrieval import loader
from app.retrieval.models import REGISTRY

_IGNORE_PATTERNS = ["onnx/*", "openvino/*", ".eval_results/*", "pytorch_model.bin"]


def _tree_size_bytes(root: str) -> int:
    """Total size of a snapshot, following the blob symlinks, deduplicated."""
    seen: set[str] = set()
    total = 0
    for dirpath, _dirnames, filenames in os.walk(root):
        for name in filenames:
            real = os.path.realpath(os.path.join(dirpath, name))
            if real in seen:
                continue
            seen.add(real)
            try:
                total += os.path.getsize(real)
            except OSError:
                pass
    return total


def provision(model_key: str) -> None:
    spec = REGISTRY[model_key]
    from huggingface_hub import snapshot_download  # lazy: needs the ML image

    path = snapshot_download(
        repo_id=spec.hf_id,
        revision=spec.revision,
        cache_dir=loader.hub_cache_dir(),
        ignore_patterns=_IGNORE_PATTERNS,
    )
    size_mb = _tree_size_bytes(path) / (1024 * 1024)
    print(f"model_key : {spec.model_key}")
    print(f"hf_id     : {spec.hf_id}")
    print(f"revision  : {spec.revision}")
    print(f"kind      : {spec.kind}" + (f" (dims={spec.dims})" if spec.dims else ""))
    print(f"license   : {spec.license}")
    print(f"location  : {path}")
    print(f"size      : {size_mb:.1f} MiB")
    print()


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(
        prog="python -m app.retrieval.provision",
        description="Download the pinned retrieval models into the local HF cache.",
    )
    group = parser.add_mutually_exclusive_group(required=True)
    group.add_argument("--model-key", choices=sorted(REGISTRY), help="provision one model")
    group.add_argument("--all", action="store_true", help="provision every registry model")
    args = parser.parse_args(argv)

    keys = sorted(REGISTRY) if args.all else [args.model_key]
    for key in keys:
        provision(key)
    return 0


if __name__ == "__main__":
    sys.exit(main())
