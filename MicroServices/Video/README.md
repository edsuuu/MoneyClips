# video

Serviço de vídeo do MoneyClips — tudo que é ffmpeg mora aqui. Duas
responsabilidades, dois endpoints, **duas filas independentes** (um
empacotamento de 3h não pode fazer o Laravel esperar num reencode síncrono):

- **`/package`** — empacota vídeos longos em **HLS/ABR** para reprodução rápida
  no navegador: um MP4 de 3GB servido direto obriga o player a puxar o bitrate
  cheio do original; em HLS ele começa em segundos e troca de qualidade
  conforme a banda.
- **`/reencode`** — recodifica shorts de bitrate baixo antes da publicação (o
  TikTok recusa alguns vídeos por "baixa qualidade").

Node 22 + Express + ffmpeg, nativo (sem Docker). Porta **8790**.

## Contrato

```
GET  /health    → {status, encoder, segment_seconds, queued,          (aberto)
                   reencode_enabled, threshold_kbps, reencodes_running}

POST /package   → 202 {uuid}                                     (X-Api-Token)
     JSON {video_key, output_prefix, webhook_url}   — assíncrono, via webhook

POST /reencode  → 200 binário do vídeo (header X-Reencode: completed)
                  ou 200 {status: "skipped"} quando o bitrate já está ok
     multipart {video: <arquivo>, video_id?: "abc"}  — SÍNCRONO, sem S3
```

O desfecho chega no webhook do Laravel (`POST /api/hls/webhook`):

```jsonc
// durante o encode, a cada ~10s
{"uuid": "...", "status": "progress", "progress": 42}

// sucesso
{"uuid": "...", "status": "done", "duration_seconds": 3600, "width": 1920,
 "height": 1080, "hash": "<md5>", "renditions": ["360p","720p","1080p"],
 "poster": true}

// o arquivo não era um vídeo legível (terminal, sem retry)
{"uuid": "...", "status": "rejected", "error": "..."}

// falha do serviço (a fonte continua intacta)
{"uuid": "...", "status": "failed", "error": "..."}
```

O `API_TOKEN` também vai como `X-Observability-Token` no webhook e precisa
bater com o `OBSERVABILITY_TOKEN` do Laravel — **sem isso o desfecho é
recusado e o vídeo trava em `packaging`**.

## Exceção à regra "só o Laravel toca o S3" (só o `/package`)

Este é o segundo serviço com credencial de storage (o outro é o
`download-shorts`). Um vídeo longo vira **milhares** de segmentos: trafegá-los
por HTTP até o Laravel para subir um a um prenderia um worker da fila por
horas. Aqui o serviço lê `uploads/*` e escreve `hls/*` direto.

O `/reencode` **não** usa storage: o Laravel envia o binário por multipart e
recebe o resultado na resposta — um short cabe numa requisição.

Use uma credencial **dedicada** (do provedor S3 em uso), com policy restrita a esses dois
prefixos — o serviço não deve conseguir tocar `shorts/` nem apagar fontes.

## Saída

```
hls/{uuid}/
  master.m3u8          # URIs relativas — servidas sob o mesmo prefixo autenticado
  poster.jpg
  360p/{init_0.mp4, index.m3u8, seg_00000.m4s, ...}
  720p/{init_1.mp4, index.m3u8, seg_00000.m4s, ...}
```

## Decisões de encode

- **Um decode, N encodes** (`split` no `filter_complex`): rodar um ffmpeg por
  rendition decodificaria a fonte várias vezes.
- **Keyframes alinhados** (`-force_key_frames` + `-sc_threshold 0`): sem isso
  os segmentos não são intercambiáveis e o player trava ao trocar de qualidade.
- **fMP4/CMAF** em vez de TS: menos overhead e os mesmos segmentos servem DASH.
- **Nunca faz upscale**: o ladder é montado a partir do ffprobe — fonte 720p
  gera `[360p, 720p]`, nunca 1080p. O bitrate de cada degrau também é limitado
  ao da fonte.
- **Fast path**: fonte já H.264/AAC com um degrau útil é apenas remuxada
  (`-c copy`) — segundos em vez de horas.
- **1 ffmpeg por vez por fila** (promise-chain): o encode monopoliza CPU/GPU.
  HLS e reencode têm filas separadas — o `/reencode` é síncrono e não pode ficar
  atrás de um empacotamento de horas.
- **GPU por SO, com fallback**: `HLS_ENCODER=gpu` (default) escolhe o encoder de
  hardware conforme a plataforma — `h264_videotoolbox` no macOS,
  `h264_nvenc` no Linux/Windows com GPU NVIDIA. A detecção é um encode de teste
  real (não só a lista do ffmpeg), e cai para `libx264` se o hardware recusar —
  inclusive em runtime, no meio de um job. `HLS_ENCODER=cpu` força libx264, útil
  para liberar a GPU ao AutoCaption (WhisperX/CUDA), que disputa o mesmo
  hardware em máquinas NVIDIA.

## Decisões do reencode

- Só recodifica quando o bitrate do stream de vídeo está **abaixo do limiar**
  (`REENCODE_BITRATE_THRESHOLD_KBPS`, default 4000) — senão devolve `skipped`.
- **Qualidade constante** (CQ/CRF 18), sem bitrate-alvo: `h264_nvenc` (GPU) com
  fallback para `libx264`, inclusive quando o NVENC falha em runtime.
- **Nunca derruba a postagem**: ffprobe/ffmpeg falhando = devolve `skipped` e o
  Laravel publica o original.

## Rodar

```bash
cp .env.example .env
pnpm install
pnpm dev      # ou: pnpm start
```

Precisa de `ffmpeg` e `ffprobe` no host. Sobe junto com o resto no `make up`.
