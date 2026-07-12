# Reencode (microserviço)

Recodifica vídeos de baixo bitrate antes da publicação nas redes. Extraído do
`TikTokUploader` para poder ser reusado por qualquer poster (TikTok, YouTube,
etc.) sem acoplar o reencode ao browser.

Node 22 + TypeScript (tsx), porta **8790**. **Não tem banco** — o ciclo de vida
fica no Laravel, via callback de webhook (mesmo padrão do `download-shorts` e do
`tiktok-uploader`).

## Fluxo

```
POST /reencode  →  baixa source_key do S3  →  ffprobe mede o bitrate
   ├─ bitrate ≥ limiar  →  skipped   (output_key = source_key, nada é enviado)
   └─ bitrate <  limiar →  recodifica CQ/CRF 18 (NVENC/libx264) → sobe _HQ → completed
              →  webhook_url recebe o resultado
```

Reencode é serial (fila com concorrência 1) — ffmpeg é pesado de CPU/GPU.

## Rotas

| Rota | Descrição |
| --- | --- |
| `GET /health` | heartbeat: `{ status, queue_size, reencode_enabled }` |
| `POST /reencode` | enfileira um job; responde `202 { job_id, status: "queued" }` |

### `POST /reencode`

```json
{
  "video_id": "abc",
  "webhook_url": "http://host.docker.internal:8000/api/reencode/callback",
  "source_key": "shorts/abc/short_abc.mp4",
  "output_key": "shorts/abc/short_abc_HQ.mp4"
}
```

- `source_key` (opcional): sem ela, usa `${S3_PREFIX}{id}/short_{id}.mp4`.
- `output_key` (opcional): sem ela, deriva da origem inserindo `_HQ` antes da
  extensão.

### Callback (webhook)

```json
{
  "job_id": "…",
  "video_id": "abc",
  "status": "completed | skipped | failed",
  "source_key": "shorts/abc/short_abc.mp4",
  "output_key": "shorts/abc/short_abc_HQ.mp4",
  "reencoded": true,
  "error": null,
  "finished_at": "2026-07-12T…Z"
}
```

**Use sempre `output_key` a jusante** — é o `_HQ` quando recodificou, ou a
própria origem quando `skipped`/`failed`.

## Rodar

```bash
cp .env.example .env         # ajuste as credenciais do S3
pnpm install
pnpm start                   # ou: pnpm dev (watch)
```

Docker (via compose na raiz do repo):

```bash
docker compose up -d --build reencode
```

NVENC (GPU) acelera o reencode; requer `nvidia-container-toolkit` no host e o
bloco `deploy.resources` descomentado no compose. Sem GPU cai para libx264 (CPU)
automaticamente. `REENCODE_ENABLED=false` desliga (vira passthrough).
