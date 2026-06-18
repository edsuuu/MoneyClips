# MicroServices

Conteúdo centralizado (monorepo) dos serviços que o **generate-clips-laravel**
orquestra. Antes viviam em repos separados (`edsuuu/download-shorts`,
`edsuuu/tiktok-uploader`, `edsuuu/generate-clips`); aqui são a fonte de verdade
local. A pasta é versionada — só `.env`, `cookies/*.json`, `.venv/`,
`node_modules/`, `models/` e caches ficam fora do git (ver `.gitignore` da raiz).

## Os serviços

| Serviço | Stack | Porta | Docker? | Acessa |
| --- | --- | --- | --- | --- |
| `download-shorts` | Python / FastAPI | 8770 | ✅ (compose) | S3/MinIO (sem banco — webhook por item) |
| `TikTokAutoUploader` | Node 22 + Playwright | 8090 | ✅ (compose) | S3/MinIO, TikTok (web), Discord |
| `generate-clips` | Python / FastAPI | 8765 | ❌ nativo no host | MinIO, LLMs, Whisper, ffmpeg |

> **generate-clips não está no Docker.** Usa GPU (Whisper MLX/Metal e ffmpeg
> videotoolbox no macOS), que não existe em container no Docker Desktop. Continua
> rodando nativo no host — ver abaixo.

## Como subir (a partir da RAIZ do projeto Laravel)

```bash
make micro-setup     # cria os .env faltantes a partir dos .env.example
# revise MicroServices/*/.env (segredos, contas, DRY_RUN...)
make micro-up        # build + sobe download-shorts (8770) e tiktok-uploader (8090)
make micro-ps        # status
make micro-logs      # logs ao vivo
```

### Pré-requisitos no host
- **Docker Desktop**.
- **MinIO** no ar (S3-compatível) com o bucket esperado pelos serviços
  (o compose default usa `auto-post` com `minioadmin/minioadmin`).
- **Laravel** servindo em `0.0.0.0` para receber os callbacks dos containers:
  `php artisan serve --host=0.0.0.0` (o `composer dev` já levanta o restante).

### Rede (importante)
Os containers usam bridge networking + `host.docker.internal`:
- O **Laravel (host) → containers** pelas portas publicadas: `127.0.0.1:8770`,
  `127.0.0.1:8090`.
- Os **containers → host** (MinIO e callbacks do Laravel) via
  `host.docker.internal`. Por isso o `docker-compose.yml` na raiz sobrescreve
  `STORAGE_ENDPOINT`/`AWS_ENDPOINT` e o `.env` do Laravel aponta os
  callbacks (`*_WEBHOOK_URL`/`*_CALLBACK_URL`) para `host.docker.internal:8000`.

## generate-clips (nativo no host)

```bash
cd MicroServices/generate-clips
python -m venv .venv && source .venv/bin/activate   # primeira vez
pip install -r requirements.txt
cp .env.example .env   # se ainda não existir
python main.py         # sobe a API em 127.0.0.1:8765
```

## TikTok — sessão/cookies

O container é **headless** (sem QR Code). Gere os cookies de sessão **fora** do
container (login local com `HEADLESS=false`) e eles são carregados pelo volume
`MicroServices/TikTokAutoUploader/cookies/`. Mantenha `DRY_RUN=true` enquanto
testa — publicação é irreversível.

## Voltou a divergir do repo original?

Cada serviço ainda tem remote próprio no GitHub. Esta cópia é a fonte de verdade
local do monorepo; sincronize manualmente com os repos originais se precisar.
