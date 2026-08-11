"""Mnemosyne AI worker.

Exposes liveness/readiness endpoints plus the internal versioned EPUB
processing API (/internal/v1/epub/*, token-protected). Interactive API
docs stay disabled.
"""

import os

from fastapi import FastAPI, Response

from app.routers.internal_v1 import router as internal_v1_router
from app.routers.retrieval_v1 import router as retrieval_v1_router

SERVICE_NAME = "mnemosyne-ai-worker"

app = FastAPI(title=SERVICE_NAME, docs_url=None, redoc_url=None, openapi_url=None)
app.include_router(internal_v1_router)
app.include_router(retrieval_v1_router)


def _enabled_checks() -> list[str]:
    raw = os.environ.get("WORKER_READINESS_CHECKS", "db,redis,storage")
    return [item.strip() for item in raw.split(",") if item.strip()]


def _check_db() -> bool:
    try:
        import psycopg

        conninfo = (
            f"host={os.environ.get('DB_HOST', 'pg')} "
            f"port={os.environ.get('DB_PORT', '5432')} "
            f"dbname={os.environ.get('DB_DATABASE', 'mnemosyne')} "
            f"user={os.environ.get('DB_USERNAME', 'mnemosyne')} "
            f"password={os.environ.get('DB_PASSWORD', '')} "
            "connect_timeout=3"
        )
        with psycopg.connect(conninfo) as conn:
            conn.execute("SELECT 1")
        return True
    except Exception:
        return False


def _check_redis() -> bool:
    try:
        import redis

        client = redis.Redis(
            host=os.environ.get("REDIS_HOST", "redis"),
            port=int(os.environ.get("REDIS_PORT", "6379")),
            password=os.environ.get("REDIS_PASSWORD") or None,
            socket_connect_timeout=3,
        )
        return bool(client.ping())
    except Exception:
        return False


def _check_storage() -> bool:
    path = os.environ.get("WORKER_DATA_PATH", "/data")
    return os.path.isdir(path) and os.access(path, os.W_OK)


def _ollama_status() -> str:
    """Ollama is an optional dependency: it never affects readiness."""
    base_url = os.environ.get("OLLAMA_BASE_URL", "http://host.docker.internal:11434")
    try:
        import httpx

        response = httpx.get(f"{base_url.rstrip('/')}/api/tags", timeout=3)
        return "available" if response.status_code == 200 else "unavailable"
    except Exception:
        return "unavailable"


@app.get("/health/live")
def health_live() -> dict:
    return {"status": "ok", "service": SERVICE_NAME}


@app.get("/health/ready")
def health_ready(response: Response) -> dict:
    checks: dict[str, str] = {}
    healthy = True

    for name in _enabled_checks():
        check = {"db": _check_db, "redis": _check_redis, "storage": _check_storage}.get(name)
        if check is None:
            continue
        ok = check()
        checks[name] = "ok" if ok else "failed"
        healthy = healthy and ok

    if not healthy:
        response.status_code = 503

    return {
        "status": "ok" if healthy else "degraded",
        "service": SERVICE_NAME,
        "checks": checks,
        "ollama": _ollama_status(),
    }
