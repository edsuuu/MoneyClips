# media

Microservico Python unico do projeto — download do YouTube + transcricao,
cada um na sua propria fila (um download longo nunca segura uma transcricao):

1. **Shorts de um canal** em lote — 1 webhook por item terminado.
2. **Um video longo por URL** — fila assincrona + webhook de desfecho (usado
   pelo import da tela /upload do Laravel).
3. **Transcricao de audio** (faster-whisper) — fila propria + webhook com o
   transcript por palavra (chamado pelo servico Video e pelo Laravel).

Sem banco, sem migrations, sem polling. Tudo vive no processo.

## Rodar (nativo, sem Docker)

```bash
cd MicroServices/Media
python3 -m venv .venv
.venv/bin/python -m pip install -r requirements.txt
cp .env.example .env   # ajuste credenciais/token
.venv/bin/python -m app.main
```

Default port: `8770`. Na raiz do repo, `make up` sobe junto com o resto.

## Shorts de canal

```bash
curl -X POST http://127.0.0.1:8770/shorts/download \
  -H 'Content-Type: application/json' \
  -d '{
    "channel_url": "https://www.youtube.com/@canal",
    "webhook_url": "https://app.com/api/webhook/download-youtube"
  }'
```

Resposta sincrona (apos listar via yt-dlp):
`202 {"status":"started","count":42,"channel_url":"..."}`.
Canal ja ativo no processo → `409 channel already downloading`.

Webhook por item (retry com backoff 1s/5s/15s):

```json
{
  "channel_url": "https://www.youtube.com/@canal",
  "items": [
    {
      "youtube_id": "abc123",
      "title": "Titulo do video",
      "hashtags": ["#a", "#b"],
      "status": "completed",
      "storage_path": "shorts/abc123/short_abc123.mp4",
      "storage_size_bytes": 123456,
      "storage_mime_type": "video/mp4"
    }
  ]
}
```

Para falhas, `status: "failed"` e `error` no item.

## Video longo por URL

```bash
curl 'http://127.0.0.1:8770/videos/metadata?url=https://youtu.be/abc123'
# 200 {youtube_id, title, duration_seconds, width, height, channel}
# 400 URL invalida ou live | 404 video indisponivel | 502 erro do extractor
```

```bash
curl -X POST http://127.0.0.1:8770/videos/download \
  -H 'Content-Type: application/json' \
  -d '{
    "url": "https://youtu.be/abc123",
    "video_uuid": "uuid-do-laravel",
    "video_key": "videos/uuid/uuid.mp4",
    "webhook_url": "https://app.com/api/webhook/download-video"
  }'
# 202 {"video_uuid":"...","status":"queued"}
```

Fila de 1 consumidor. Baixa sempre em ate 1080p (preferindo 1080p/H.264);
sem 1080p pega a melhor resolucao/bitrate abaixo; falha de formato cai numa
cadeia de fallback progressiva (par dash → progressivo → qualquer). Remux pra
mp4 (sem transcode) e sobe na `video_key` EXATA. Webhook de desfecho (com
header `X-Observability-Token`):

```json
{"video_uuid": "...", "status": "completed", "size_bytes": 123, "title": "...", "duration_seconds": 90, "width": 1920, "height": 1080}
```

Para falhas, `{"video_uuid": "...", "status": "failed", "error": "..."}`.

## Transcricao

```bash
curl -X POST http://127.0.0.1:8770/transcriptions \
  -F 'audio=@audio.wav' \
  -F 'uuid=uuid-do-chamador' \
  -F 'webhook_url=https://app.com/api/webhook/transcribe'
# 202 {"job_id":"...","status":"queued"}
```

Fila de 1 consumidor com `gpu_lock` (a GPU nao e reentrante). Em macOS o
device cai pra `cpu/int8` automaticamente; CUDA so em Linux/NVIDIA. Webhook
de desfecho (com header `X-Observability-Token`):

```json
{"uuid": "...", "status": "done", "transcript": {"segments": [{"start": 0.0, "end": 2.1, "text": "...", "words": [{"word": "...", "start": 0.0, "end": 0.4, "score": 0.98}]}], "language": "pt"}}
```

Para falhas, `{"uuid": "...", "status": "failed", "error": "..."}`.

## Estrutura

```text
app/
  main.py                    # /health + /shorts/download + /videos/metadata + /videos/download + /transcriptions
  jobs/worker.py             # pool de shorts + webhook por item
  jobs/video_worker.py       # fila de video longo + webhook de desfecho
  transcription/worker.py    # fila de transcricao + webhook de desfecho
  transcription/transcribe.py # faster-whisper (modelo lazy, word timestamps)
  transcription/device.py    # device por S.O. (macOS → cpu/int8)
  youtube/client.py          # wrappers yt_dlp (listagem, metadata, downloads)
  storage/client.py          # wrapper boto3 S3
  config/settings.py
```

## Configuracao

| Var | O que faz |
| --- | --- |
| `DOWNLOAD_WORKERS` | tamanho do `ThreadPoolExecutor` dos shorts |
| `MAX_ATTEMPTS` | tentativas por short (download+upload+verify) |
| `WEBHOOK_TIMEOUT_SECONDS` | timeout de cada POST de webhook |
| `STORAGE_*` | credenciais e bucket S3-compativel |
| `GPU_ENCODER` | transcode dos shorts: none/nvenc/videotoolbox |
| `WHISPER_MODEL`/`WHISPER_LANGUAGE` | modelo e idioma do faster-whisper |
| `WHISPER_DEVICE`/`WHISPER_COMPUTE_TYPE` | cuda/float16 em producao (macOS ignora e usa cpu/int8) |
| `OBSERVABILITY_URL`/`OBSERVABILITY_TOKEN`/`SERVICE_NAME` | logs+heartbeat pro Laravel; o token tambem assina os webhooks de video longo e transcricao |
