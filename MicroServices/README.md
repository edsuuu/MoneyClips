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
| `TikTokUploader` | Node 22 + Playwright + Express | 8090 | `POST /posts` **multipart síncrono** `{video, cookies, title, hashtags}` → `{status: completed\|dry-run\|restricted}`; `401` = cookies inválidos. Também `POST /session` e `POST /login` (login por credenciais) |
| `Reencode` | Node 22 + Express + ffmpeg | 8790 | `POST /reencode` **multipart síncrono** `{video, video_id?}` → binário `_HQ` (header `X-Reencode: completed`) ou JSON `{status: "skipped"}`; 1 ffmpeg por vez; `API_TOKEN` opcional |
| `AutoCaption` | Python / FastAPI + WhisperX (CUDA) | 8780 | `POST /videos` multipart `{file, variants, caption_position, channel_name, channel_handle, webhook_url}` → 202 `{uuid}`; webhook `{uuid, status: done\|failed}`; output em `GET /videos/{uuid}/output/{variant}` |
| `GenerateClips` | Python / FastAPI | 8765 | fora do fluxo atual — não entra no `make up` |

### Observabilidade (OBSERVABILITY.md na raiz)

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
cd MicroServices/Reencode && pnpm dev
cd MicroServices/AutoCaption && .venv/bin/python -m app.main
```

> **AutoCaption e GPU:** o pipeline completo (WhisperX + NVENC) exige
> CUDA/Linux. Em macOS o serviço sobe, mas o render falha gracioso — o
> Laravel registra `processing_jobs.failed` e avisa no Discord.
