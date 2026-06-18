# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this service is

Independent Python (FastAPI) microservice that downloads all YouTube Shorts of a channel, uploads each file to S3-compatible storage, and POSTs a webhook **per item finished** (success or failure). Default port `8770`. **No database** — the orchestrator (Laravel) persists the items it receives.

**Hard constraint:** este repo deve ficar independente do pipeline `generate-clips`. Não importe módulos dele. Use terminologia `storage` genérica nos contratos; nomes de provedor (Contabo etc) ficam só no `.env`.

## Commands

Docker é o canal oficial. O `docker-compose.yml` vive na **raiz do
generate-clips-laravel** (não dentro deste diretório) e já traz defaults de
dev (MinIO local em `host.docker.internal:9000` com `minioadmin/minioadmin`,
bucket `auto-post`). Não precisa de `.env` aqui pra subir.

```bash
# Da raiz do Laravel:
docker compose up -d --build download-shorts
docker compose logs -f download-shorts
```

Standalone (sem Docker, com virtualenv):

```bash
# Setup
python -m venv .venv
.venv/bin/python -m pip install -r requirements.txt
cp .env.example .env

# Run
.venv/bin/python -m app.main          # serve em $API_HOST:$API_PORT (default 8770)

# Lint / format / types
.venv/bin/ruff check app
.venv/bin/ruff format app
.venv/bin/mypy app

# Mínimo de validação (sem suite de testes)
.venv/bin/python -m compileall app
```

## Configuration

Settings via `.env` em `pydantic-settings` (`app/config/settings.py`). Storage usa vars `STORAGE_*`; tuning via `DOWNLOAD_WORKERS` (default 4), `MAX_ATTEMPTS` (default 3), `WEBHOOK_TIMEOUT_SECONDS` (default 30).

## Architecture

`POST /shorts/download` → `start_download` lista os Shorts (síncrono) → resposta `202 {count}` → thread em background dispara o pool (`DOWNLOAD_WORKERS`) → cada thread baixa + sobe + dispara webhook do item (retry 1s/5s/15s).

- `app/main.py` — FastAPI: `GET /health` e `POST /shorts/download`.
- `app/jobs/worker.py` — `start_download(channel_url, webhook_url)`, pool, item processor, retry de webhook. Mantém um set em memória (`_active_channels`) que impede 2 jobs concorrentes pro mesmo canal.
- `app/youtube/client.py` — wrappers `yt_dlp`: `list_shorts`, `download_short`.
- `app/storage/client.py` — wrapper `boto3` (`exists`, `stat`, `upload_file`) e `storage_path_for(youtube_id)`.

### Item state machine

`downloading → uploading → verifying → completed | failed` (interno à thread; não persiste).

Invariantes ao editar o worker:
- Item só vira `completed` depois que o objeto existe no storage com `size_bytes > 0`. Se já existir antes do download, curto-circuita pra `completed` (idempotência).
- 3 tentativas (`MAX_ATTEMPTS`). Falhou tudo? Webhook com `status: "failed"` + `error`.
- Falha de webhook nunca derruba o serviço — log + segue.
- `_active_channels`: trava 2º disparo pro mesmo canal. Reinício do processo limpa.

### Webhook decoupling

`webhook_url` é **obrigatório**. Cada item completado/falhado vira 1 POST. Payload: `{ channel_url, items: [item] }` — o orquestrador (Laravel) trata `items[]` como uma lista (que aqui sempre tem 1 elemento), o que mantém compat com o receiver atual.

## Endpoints

- `GET /health`
- `POST /shorts/download` — `{channel_url, webhook_url}` → `202 {status, count, channel_url}`. `409` se já há download ativo pro canal.
