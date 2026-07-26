# MicroServices

Monorepo dos serviços que o **MoneyClips** (Laravel) orquestra. A pasta é
versionada — só `.env`, `cookies/*.json`, `.venv/`, `node_modules/`, `models/`
e caches ficam fora do git (ver `.gitignore` da raiz).

**Tudo roda NATIVO — sem Docker.** O `make up` na raiz sobe o Laravel + os 4
serviços do fluxo atual num terminal só; `make setup` prepara deps/envs.
Em produção, pm2/systemd por serviço.

## Regra da casa: só o Laravel toca o S3

Os serviços de processamento/postagem **não têm credencial de storage**: o
Laravel envia o binário por HTTP (multipart) e recebe/baixa o resultado.
Exceção: `download-shorts` (produtor de vídeo) sobe direto pro MinIO.

## Os serviços

| Serviço | Stack | Porta | Contrato |
| --- | --- | --- | --- |
| `DownloadShorts` | Python / FastAPI + yt-dlp | 8770 | `POST /shorts/download {channel_url, webhook_url}` → 202; baixa em pool e dispara 1 webhook por item; sobe direto pro MinIO |
| `TikTokUploader` | Node 22 + Playwright + Express | 8090 | `GET /health` → `{status, dry_run, queue}`; `POST /posts` **multipart assíncrono** `{video, cookies, title, hashtags, webhook_url}` → `202 {job_id}`; fila serial em memória; webhook de conclusão `{job_id, status: completed\|dry-run\|restricted\|failed, session_status, refreshed_cookies?}`. Também `POST /session` e `POST /login` (login por credenciais) |
| `Video` | Node 22 + Express + ffmpeg + sharp | 8790 | Todo o ffmpeg da aplicação, 5 endpoints e filas independentes. `POST /reencode` **multipart síncrono** `{video, video_id?}` → binário `_HQ` (header `X-Reencode: completed`) ou JSON `{status: "skipped"}`, sem S3. `POST /package` **assíncrono** `{video_key, output_prefix, webhook_url}` → `202 {uuid}`; HLS/ABR lendo/escrevendo MinIO direto; webhook `{uuid, status: done\|failed\|rejected\|progress}`. `POST /cut` **assíncrono** `{cut_uuid, video_key, start_seconds, end_seconds, clip_key, audio_key, webhook_url}` → `202 {uuid}`; corte frame-exato (cap 1080p) + WAV; webhook `{uuid, cut_uuid, status: done\|failed, audio}`. `POST /reframe` **assíncrono** `{edit_uuid, source_key, output_key, source, keyframes, settings, transcript?, webhook_url}` → `202 {uuid}`; render do corte editado em 1080x1920 (zoompan por keyframes + legenda opcional); webhook `{uuid, edit_uuid, status: done\|failed}`. `POST /videos` **assíncrono** multipart `{file, variants, caption_position, channel_name, channel_handle, webhook_url}` → `202 {uuid}`; legenda karaokê + template; webhook `{uuid, status: done\|failed, files}`; output em `GET /videos/{uuid}/output/{variant}`. `API_TOKEN` opcional |
| `Transcriber` | Python / FastAPI + faster-whisper (CUDA) | 8780 | **Só transcrição.** `POST /transcribe` multipart `{audio}` (wav mono 16kHz) → `{segments: [{start, end, text, words: [{word, start, end, score}]}], language}`. Quem chama é o `Video`, não o Laravel |
| `GenerateClips` | Python / FastAPI | 8765 | fora do fluxo atual — não entra no `make up` |

### Observabilidade

Todos os 4 serviços do fluxo têm push de logs + heartbeat pro Laravel
(`RemoteObservability.ts` nos Node, `observability.py` nos Python) —
fire-and-forget, o console/pm2 continua a saída primária. Envs por serviço:

```
OBSERVABILITY_URL=http://127.0.0.1:8000/api/observability
OBSERVABILITY_TOKEN=<mesmo token do Laravel>
SERVICE_NAME=<nome do serviço>
```

## Rede

- **Laravel → serviço**: `127.0.0.1:<porta>` (envs `*_URL` no `.env` do Laravel).
- **Serviço → Laravel** (webhooks/observabilidade): `127.0.0.1:8000` em dev;
  em produção, o domínio real (nginx :80/HTTPS).
- MinIO/MySQL externos no host (`127.0.0.1`).

## Subir um serviço isolado

```bash
cd MicroServices/DownloadShorts && .venv/bin/python -m app.main
cd MicroServices/TikTokUploader && pnpm dev
cd MicroServices/Video && pnpm dev
cd MicroServices/Transcriber && .venv/bin/python -m app.main
```

> **Transcriber e GPU:** a transcrição (faster-whisper) exige CUDA/Linux. Em
> macOS o serviço sobe e o render do template roda normal no `Video`
> (ffmpeg/libx264), mas um job COM legenda falha gracioso na transcrição — o
> Laravel registra `processing_jobs.failed` e avisa no Discord.
