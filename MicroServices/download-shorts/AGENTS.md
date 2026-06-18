# AGENTS.md

Guidance for Codex when working in this repository.

## What This Project Does

Independent Python microservice for downloading YouTube Shorts from a channel.

It receives `channel_url` and `webhook_url`. It lists all Shorts for the channel, downloads them in parallel (`ThreadPoolExecutor`), uploads each to S3-compatible storage, and POSTs a single-item webhook **per item finished** (success or failure). There is no database — the lifecycle lives only in the worker thread.

This repository must stay independent from the parent `generate-clips` pipeline. Do not import modules from that project.

## Common Commands

Via Docker (canal oficial — sobe a partir da RAIZ do generate-clips-laravel,
ja com defaults de dev no `docker-compose.yml`):

```bash
docker compose up -d --build download-shorts
docker compose logs -f download-shorts
```

Sem Docker (rodando standalone com virtualenv):

```bash
python -m venv .venv
.venv/bin/python -m pip install -r requirements.txt

cp .env.example .env
.venv/bin/python -m app.main

.venv/bin/python -m compileall app
```

Default API port is `8770`.

```bash
curl http://127.0.0.1:8770/health

curl -X POST http://127.0.0.1:8770/shorts/download \
  -H 'Content-Type: application/json' \
  -d '{
    "channel_url": "https://www.youtube.com/@canal",
    "webhook_url": "https://example.com/api/shorts/callback"
  }'
# 202 Accepted: {"status":"started","count":N,"channel_url":"..."}
```

## Architecture

- `app/main.py` — FastAPI: `GET /health` + `POST /shorts/download`.
- `app/jobs/worker.py` — `start_download(channel_url, webhook_url)`: lista os Shorts (sync), guarda em set em memória os canais em download, e dispara o pool. Cada thread baixa, sobe, e dispara o webhook do próprio item (retry com backoff 1s/5s/15s).
- `app/youtube/` — wrappers de `yt_dlp` (`list_shorts`, `download_short`).
- `app/storage/` — wrapper S3-compatível via `boto3`.
- `app/config/settings.py` — `pydantic-settings` (storage, workers, retries, timeouts).

Keep this project simple. Avoid adding extra service layers unless there is a concrete need.

## Data Flow

1. `POST /shorts/download` → lista os Shorts via yt-dlp (síncrono).
2. Resposta `202 {count: N}` é retornada imediatamente.
3. Thread em background processa em pool de `DOWNLOAD_WORKERS` (default 4).
4. Por item: baixa → sobe storage → verifica → POST webhook com o item.
5. Em falha: 3 tentativas com backoff; depois envia webhook com `status: "failed"`.

## Webhook payload (1 item por chamada)

```json
{
  "channel_url": "https://www.youtube.com/@canal",
  "items": [
    {
      "youtube_id": "abc",
      "title": "...",
      "hashtags": ["#a", "#b"],
      "status": "completed",
      "storage_path": "shorts/abc.mp4",
      "storage_size_bytes": 1234567,
      "storage_mime_type": "video/mp4"
    }
  ]
}
```

Para falhas, `status: "failed"` e campo `error` no item.

## Important Constraints

- Use generic `storage` terminology in API contracts, docs, and code comments.
- Não exponha nomes de provedor (Contabo etc) nos payloads.
- Não marque um item como `completed` antes do objeto existir no storage com tamanho > 0.
- Default de concorrência modesto: `DOWNLOAD_WORKERS=4`.
- Webhook por item; 3 tentativas com backoff (1s/5s/15s) antes de desistir; falha não derruba o serviço.
- Um mesmo `channel_url` não pode disparar 2 jobs concorrentes (set em memória). O serviço responde `409 channel already downloading`.

## Testing And Validation

Sem suite automatizada. Mínimo:

```bash
.venv/bin/python -m compileall app
```

Para teste integrado, rode um storage S3-compatível, dispare um download e verifique que cada item finalizado chega no webhook.
