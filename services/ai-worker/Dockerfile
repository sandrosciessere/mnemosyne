# Mnemosyne AI worker — bootstrap skeleton.
# Python 3.12 (not the host 3.13) for maximum compatibility with the
# future ML stack. No ML dependencies are installed at this stage.
FROM python:3.12-slim

RUN groupadd -g 1003 mnemosyne && useradd -u 1003 -g 1003 -m mnemosyne

WORKDIR /srv/ai-worker

COPY requirements.txt requirements-dev.txt ./
RUN pip install --no-cache-dir -r requirements.txt

COPY app ./app
COPY tests ./tests
COPY pyproject.toml ./

USER mnemosyne

EXPOSE 8000

CMD ["uvicorn", "app.main:app", "--host", "0.0.0.0", "--port", "8000"]
