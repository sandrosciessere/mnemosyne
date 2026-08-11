# Mnemosyne AI worker.
# Python 3.12 (not the host 3.13) for maximum compatibility with the
# ML stack (CPU-only torch + sentence-transformers; no CUDA wheels).
FROM python:3.12-slim

RUN groupadd -g 1003 mnemosyne && useradd -u 1003 -g 1003 -m mnemosyne

WORKDIR /srv/ai-worker

COPY requirements.txt requirements-dev.txt ./
RUN pip install --no-cache-dir --extra-index-url https://download.pytorch.org/whl/cpu -r requirements.txt

COPY app ./app
COPY tests ./tests
COPY pyproject.toml ./

USER mnemosyne

EXPOSE 8000

CMD ["uvicorn", "app.main:app", "--host", "0.0.0.0", "--port", "8000"]
