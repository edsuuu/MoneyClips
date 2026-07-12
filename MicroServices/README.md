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
| `tiktok-uploader` | Node 22 + Playwright + Express | 8090 | ✅ (compose) | S3/MinIO, TikTok (web), Discord |
| `reencode` | Node 22 + Express + ffmpeg | 8790 | ✅ (compose) | S3/MinIO (sem banco — webhook por job) |
| `generate-clips` | Python / FastAPI | 8765 | macOS nativo / Linux NVIDIA via profile | MinIO, LLMs, Whisper, ffmpeg |

> **generate-clips e GPU:** no macOS ele continua nativo para usar
> Metal/VideoToolbox. No Linux com placa NVIDIA, o profile `linux-nvidia` sobe o
> container com CUDA/NVENC via NVIDIA Container Toolkit.

## Como subir (a partir da RAIZ do projeto Laravel)

```bash
docker compose up -d --build       # sobe laravel + download-shorts + tiktok-uploader
docker compose ps                  # status
docker compose logs -f             # logs ao vivo
docker compose down                # derruba
```

Sobe 3 containers: `laravel` (Sail PHP 8.4, porta 8000), `download-shorts`
(8770) e `tiktok-uploader` (8090). MySQL e MinIO ficam externos no host
(acessados via `host.docker.internal`).

### Pré-requisitos no host
- **Docker Desktop**.
- **MySQL** no ar (banco do `.env` do Laravel já criado).
- **MinIO** no ar (S3-compatível) com o bucket esperado pelos serviços
  (compose default: bucket `video`, `minioadmin/minioadmin`).
- O Laravel agora roda no próprio compose (service `laravel`, Sail PHP 8.4),
  então não precisa `php artisan serve` à parte. Pra dev nativo no host
  (sem container), siga rodando com `composer dev`.

### Rede (importante)
Os containers usam bridge networking + `host.docker.internal`:
- O **Laravel (host) → containers** pelas portas publicadas: `127.0.0.1:8770`,
  `127.0.0.1:8090`.
- Os **containers → host** (MinIO e callbacks do Laravel) via
  `host.docker.internal`. Por isso o `docker-compose.yml` na raiz sobrescreve
  `STORAGE_ENDPOINT`/`AWS_ENDPOINT` e o `.env` do Laravel aponta os
  callbacks (`*_WEBHOOK_URL`/`*_CALLBACK_URL`) para `host.docker.internal:8000`.

## generate-clips no macOS (nativo no host)

```bash
cd MicroServices/GenerateClips
python -m venv .venv && source .venv/bin/activate   # primeira vez
pip install -r requirements.txt
cp .env.example .env   # se ainda não existir
python main.py         # sobe a API em 127.0.0.1:8765
```

## generate-clips no Linux/NVIDIA (Docker CUDA)

Pré-requisitos no host Linux:
- Driver NVIDIA funcionando (`nvidia-smi`).
- Docker com NVIDIA Container Toolkit.

Valide e suba pelo helper:

```bash
scripts/generate-clips-docker check
scripts/generate-clips-docker up
```

Ou diretamente pelo compose:

```bash
docker compose --profile linux-nvidia up -d --build generate-clips
```

O container usa `FFMPEG_ENCODER=auto`, `FFMPEG_HWACCEL=auto`,
`WHISPER_DEVICE=auto` e `FACE_TRACKING_DELEGATE=auto`, então o próprio serviço
usa CUDA/NVENC quando a GPU NVIDIA estiver disponível.

## TikTok — sessão/cookies

O container é **headless** (sem QR Code). Gere os cookies de sessão **fora** do
container (login local com `HEADLESS=false`) e eles são carregados pelo volume
`MicroServices/TikTokUploader/cookies/`. Mantenha `DRY_RUN=true` enquanto
testa — publicação é irreversível.

## Voltou a divergir do repo original?

Cada serviço ainda tem remote próprio no GitHub. Esta cópia é a fonte de verdade
local do monorepo; sincronize manualmente com os repos originais se precisar.
