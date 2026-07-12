# AutoCaption

Microserviço que recebe um vídeo, transcreve a fala com **WhisperX (large-v3,
pt-BR)** na **GPU NVIDIA (CUDA)** com timestamps por palavra, e **queima uma
legenda karaokê** no vídeo — texto branco em MAIÚSCULO com a **palavra falada
no momento em amarelo** (estilo Shorts/TikTok). Cada vídeo vira uma pasta local
identificada por **UUID**.

> Não usa banco nem MinIO. A saída fica em `storage/<uuid>/` no filesystem.

## Fluxo

```
página HTML (upload) → POST /videos (uuid4)
  → extrai áudio (wav 16k mono)
  → WhisperX transcribe (pt) + align → timestamps por palavra
  → gera transcript.json + subtitles.srt + subtitles.ass (karaokê)
  → ffmpeg queima o .ass com h264_nvenc (GPU) → output.mp4
```

Saída em `storage/<uuid>/`: `source.*`, `audio.wav`, `transcript.json`
(word-level), `subtitles.srt`, `subtitles.ass`, `output.mp4`, `status.json`.

## O que roda na GPU

- **GPU**: inferência WhisperX (torch CUDA), **decode** do vídeo (`-hwaccel
  cuda`) e **encode** final (`h264_nvenc`).
- **CPU** (normal, rápido, não é gargalo): rasterização do texto da legenda
  pelo libass — o filtro `ass` do ffmpeg não tem caminho GPU.

Se o NVENC não estiver disponível, a queima cai automaticamente para `libx264`
(CPU). Para forçar CPU, use `GPU_ENCODER=libx264`.

## Rodar (nativo, com venv) — recomendado

Pré-requisitos no host (WSL Ubuntu): driver NVIDIA no Windows + WSL2 CUDA
(`nvidia-smi` funcionando) e `ffmpeg` com `h264_nvenc`
(`ffmpeg -hide_banner -encoders | grep nvenc`).

```bash
cd /var/www/projects/generate-clips-laravel/MicroServices/AutoCaption
python3 -m venv .venv
source .venv/bin/activate
pip install --upgrade pip
# torch CUDA primeiro (ajuste cu124/cu121 conforme o driver):
pip install torch torchaudio --index-url https://download.pytorch.org/whl/cu124
pip install -r requirements.txt
cp .env.example .env
python -m app.main
```

Depois abra **http://127.0.0.1:8780**, selecione um vídeo e clique em
"Enviar e legendar". A lista atualiza o status sozinha; quando ficar `done`,
aparece o link de download do `output.mp4`.

> O primeiro job baixa os pesos do `large-v3` e do modelo de alinhamento pt
> (alguns GB) — pode demorar. Os jobs seguintes reutilizam o modelo em memória.

## Endpoints

| Método | Rota | Função |
| --- | --- | --- |
| GET | `/` | página HTML de upload + lista de vídeos |
| POST | `/videos` | multipart `file` → cria UUID e dispara o pipeline (202) |
| GET | `/videos` | lista todos os vídeos com status |
| GET | `/videos/{uuid}` | status.json de um vídeo |
| GET | `/videos/{uuid}/output` | baixa o `output.mp4` legendado |
| GET | `/health` | `{"status":"ok"}` |

## Benchmark

Mede o tempo de cada etapa no seu hardware (GPU) e o real-time factor (RTF —
segundos de processamento por segundo de vídeo), além do pico de VRAM:

```bash
source .venv/bin/activate
python -m app.benchmark caminho/do/video.mp4          # usa nvenc + libx264 p/ comparar
python -m app.benchmark video.mp4 --encoder nvenc     # só GPU
python -m app.benchmark video.mp4 --runs 2 --json     # cold + 1 warm, + saída JSON
```

Sem argumento, ele pega o `source` mais recente em `storage/`. Saída (exemplo):

```
 etapa                    tempo (s)       RTF   nota
 extract_audio                 0.42     0.014
 transcribe_cold              18.30     0.610   inclui load do modelo
 build_subtitles               0.01     0.000
 burn_nvenc                    3.10     0.103   ok
 burn_libx264                 22.40     0.747   ok
 Velocidade: 1.28x tempo real (23.4s p/ processar 30.0s de vídeo)
```

`transcribe_cold` inclui o carregamento do modelo (uma vez por processo); use
`--runs 2` para ver o tempo "quente" (steady-state) da transcrição. `RTF < 1.0`
= mais rápido que tempo real. Compare `burn_nvenc` (GPU) vs `burn_libx264` (CPU)
para justificar o uso do NVENC.

## Config (`.env`)

Veja `.env.example`. Principais: `WHISPER_MODEL`, `WHISPER_LANGUAGE=pt`,
`WHISPER_COMPUTE_TYPE=float16`, `GPU_ENCODER`, `MAX_WORDS_PER_LINE`,
`FONT_NAME`, `FONT_SIZE`, `HIGHLIGHT_COLOR` (amarelo em ASS AABBGGRR).

## Docker (opcional, posterior)

O `Dockerfile` usa base `nvidia/cuda` e existe para containerizar depois.
Exige `nvidia-container-toolkit` no host e um bloco `deploy` de GPU no compose
(mesmo padrão comentado do `tiktok-uploader` no `docker-compose.yml` da raiz).
Por ora o run oficial é o venv nativo acima.

## Fora de escopo (1ª entrega)

- Integração com Laravel / MinIO / webhooks (só local por enquanto).
- Fila persistente / múltiplos workers (estado em memória, 1 job por vez).
