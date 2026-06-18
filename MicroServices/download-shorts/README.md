# download-shorts

Microservico independente para baixar YouTube Shorts de um canal, enviar os arquivos para um storage S3-compativel e disparar um webhook por item terminado.

Sem banco, sem migrations, sem polling. Tudo vive na thread.

## Rodar

**Via Docker (recomendado, sobe junto com os outros microservicos):**

```bash
# A partir da RAIZ do generate-clips-laravel:
docker compose up -d --build download-shorts
```

O compose ja vem com defaults de dev (MinIO local `minioadmin/minioadmin`,
bucket `auto-post`). Para producao, sobrescreva as variaveis `STORAGE_*` no
shell ou edite o `docker-compose.yml`. Nao precisa de `.env` neste diretorio
pra rodar via compose.

**Standalone (sem Docker):**

```bash
cd MicroServices/download-shorts
python -m venv .venv
.venv/bin/python -m pip install -r requirements.txt
cp .env.example .env   # ajuste se precisar de credenciais diferentes
.venv/bin/python -m app.main
```

Default port: `8770`.

## Disparar download

```bash
curl -X POST http://127.0.0.1:8770/shorts/download \
  -H 'Content-Type: application/json' \
  -d '{
    "channel_url": "https://www.youtube.com/@canal",
    "webhook_url": "https://app.com/api/shorts/callback"
  }'
```

Resposta sincrona (apos listar via yt-dlp):

```json
{"status":"started","count":42,"channel_url":"https://www.youtube.com/@canal"}
```

A partir dai, cada Short concluido (ou falhado) cai como uma chamada POST no `webhook_url`.

Se o canal ja tem um download ativo no processo, responde `409 channel already downloading`.

## Estrutura

```text
app/
  main.py            # /health + POST /shorts/download
  schemas.py
  jobs/worker.py     # pool de download e webhook por item
  youtube/client.py  # wrappers yt_dlp
  storage/client.py  # wrapper boto3 S3
  config/settings.py
```

## Payload do webhook (1 item por chamada)

```json
{
  "channel_url": "https://www.youtube.com/@canal",
  "items": [
    {
      "youtube_id": "abc123",
      "title": "Titulo do video",
      "hashtags": ["#a", "#b"],
      "status": "completed",
      "storage_path": "shorts/abc123.mp4",
      "storage_size_bytes": 123456,
      "storage_mime_type": "video/mp4"
    }
  ]
}
```

Para falhas, `status: "failed"` e `error` no item. O webhook usa retry com backoff (1s/5s/15s) antes de desistir.

## Configuracao

| Var | Default | O que faz |
| --- | --- | --- |
| `DOWNLOAD_WORKERS` | `4` | tamanho do `ThreadPoolExecutor` |
| `MAX_ATTEMPTS` | `3` | tentativas por item (download+upload+verify) |
| `WEBHOOK_TIMEOUT_SECONDS` | `30` | timeout de cada POST do webhook |
| `STORAGE_*` | — | credenciais e bucket S3-compativel |
