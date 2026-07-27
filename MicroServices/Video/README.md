# video

Serviço de vídeo do MoneyClips — **todo ffmpeg da aplicação mora aqui**. Três
responsabilidades, três endpoints, **três filas independentes** (um
empacotamento de 3h não pode fazer o Laravel esperar num reencode síncrono):

- **`/package`** — empacota vídeos longos em **HLS/ABR** para reprodução rápida
  no navegador: um MP4 de 3GB servido direto obriga o player a puxar o bitrate
  cheio do original; em HLS ele começa em segundos e troca de qualidade
  conforme a banda.
- **`/reencode`** — recodifica shorts de bitrate baixo antes da publicação (o
  TikTok recusa alguns vídeos por "baixa qualidade").
- **`/videos`** — legenda karaokê + moldura do canal (as variantes `original`,
  `vertical`, `template_white`, `template_black`).

Node 22 + Express + ffmpeg + sharp, nativo (sem Docker). Porta **8790**.

## Contrato

```
GET  /health    → {status, encoder, segment_seconds, queued,          (aberto)
                   reencode_enabled, threshold_kbps, reencodes_running,
                   captions_queued, transcriber_url}

POST /package   → 202 {uuid}                                     (X-Api-Token)
     JSON {video_uuid, video_key, hls_prefix, poster_key, audio_key,
           storyboard_key, webhook_url}   — assíncrono, via webhook
           (o Laravel decide TODOS os paths; o serviço só escreve neles)

POST /reencode  → 200 binário do vídeo (header X-Reencode: completed)
                  ou 200 {status: "skipped"} quando o bitrate já está ok
     multipart {video: <arquivo>, video_id?: "abc"}  — SÍNCRONO, sem S3

POST /videos    → 202 {uuid}                                     — assíncrono
     multipart {file, variants, caption_position, channel_name,
                channel_handle, with_captions?, watermark_text?,
                subtitle_offset?, webhook_url}
GET  /videos                      → {videos: [status...]}
GET  /videos/{uuid}               → status.json do job
GET  /videos/{uuid}/output/{variant}  → mp4 renderizado
```

O desfecho do `/package` chega no webhook do Laravel (`POST /api/hls/webhook`);
o do `/videos`, em `POST /api/autocaption/webhook` com
`{uuid, status: done|failed, error, files}`.

```jsonc
// durante o encode, a cada ~10s
{"uuid": "...", "status": "progress", "progress": 42}

// sucesso (video_uuid ecoa o recebido — é como o Laravel acha o vídeo)
{"uuid": "...", "video_uuid": "...", "status": "done", "duration_seconds": 3600,
 "width": 1920, "height": 1080, "hash": "<md5>",
 "renditions": ["360p","720p","1080p","1440p","2160p"], "poster": true, "audio": true,
 "storyboard": {"cols": 12, "rows": 11, "interval": 28,
                "tile_width": 160, "tile_height": 90}}
// poster/audio/storyboard viram false quando o passo opcional falha

// o arquivo não era um vídeo legível (terminal, sem retry)
{"uuid": "...", "video_uuid": "...", "status": "rejected", "error": "..."}

// falha do serviço (a fonte continua intacta)
{"uuid": "...", "video_uuid": "...", "status": "failed", "error": "..."}
```

O `API_TOKEN` também vai como `X-Observability-Token` no webhook e precisa
bater com o `OBSERVABILITY_TOKEN` do Laravel — **sem isso o desfecho é
recusado e o vídeo trava em `packaging`**.

## Exceção à regra "só o Laravel toca o S3" (só o `/package`)

Este é o segundo serviço com credencial de storage (o outro é o
`download-youtube`). Um vídeo longo vira **milhares** de segmentos: trafegá-los
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
  gera `[360p, 720p]`, nunca 1080p; fonte 4K sobe até `[360p ... 2160p]`. O
  bitrate de cada degrau também é limitado ao da fonte.
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
  para liberar a GPU ao `transcriber` (faster-whisper/CUDA), que disputa o mesmo
  hardware em máquinas NVIDIA.
- **VideoToolbox precisa de `-g` explícito**: ele ignora o `-force_key_frames`
  e emite keyframe a cada ~0,4s (medido num Mac: 75 num clipe de 30s, contra 5
  do libx264). As renditions ficam alinhadas mesmo assim, mas o encode
  desperdiça bits em I-frame. O ganho de velocidade dele no macOS **não foi
  medido em conteúdo real** — num teste sintético ficou mais lento que o
  libx264.

## Decisões do reencode

- Só recodifica quando o bitrate do stream de vídeo está **abaixo do limiar**
  (`REENCODE_BITRATE_THRESHOLD_KBPS`, default 4000) — senão devolve `skipped`.
- **Qualidade constante** (CQ/CRF 18), sem bitrate-alvo: `h264_nvenc` (GPU) com
  fallback para `libx264`, inclusive quando o NVENC falha em runtime.
- **Nunca derruba a postagem**: ffprobe/ffmpeg falhando = devolve `skipped` e o
  Laravel publica o original.

## Decisões da legenda/template

- **A transcrição é o único passo que continua em Python** (`transcriber`,
  :8780, faster-whisper/CUDA). Este serviço extrai o wav, faz `POST /transcribe`
  e monta tudo o que vem depois.
- **Um evento ASS por palavra**, redesenhando a linha inteira — não usa tag
  `\k`. O fim de um evento é o início do próximo, então o destaque não pisca; as
  palavras futuras ficam com `\alpha&HFF&`, invisíveis mas ainda ocupando
  largura (é o que impede a linha de pular a cada palavra).
- **Timestamp nulo é regra, não exceção**: o faster-whisper emite `start`/`end`
  nulos. O preenchimento (herda o fim da anterior, procura o início da próxima,
  piso de 50ms) está coberto por golden test — veja abaixo.
- **Linha nunca atravessa segmento**: o agrupamento roda por segmento, com teto
  de 3 palavras ou 2,5s.
- **ffmpeg roda com `cwd` no diretório do job e nomes de arquivo relativos**: o
  filtro `ass=` do libass trata `:` e `\` como metacaractere, então caminho
  absoluto quebra o filtergraph.
- **PNGs estáticos (moldura, marca d'água, máscara, logo) saem do sharp/SVG**,
  substituindo o PIL. A fonte do cabeçalho passou a ser resolvida por *nome de
  família* (fontconfig), não por caminho de TTF.

## Testes

```bash
pnpm test     # golden test da legenda
```

Compara o `.ass`/`.srt` gerados com a saída do `subtitles.py` original do
AutoCaption (`tests/golden/`, gerados pelo Python antes da migração). Legenda
dessincronizada não estoura erro nenhum — sem esse diff, uma regressão só
apareceria assistindo o vídeo.

## Rodar

```bash
cp .env.example .env
pnpm install
pnpm dev      # ou: pnpm start
```

Precisa de `ffmpeg` e `ffprobe` no host. Sobe junto com o resto no `make up`.
