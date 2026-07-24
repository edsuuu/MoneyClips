# Transcriber

Microserviço de **transcrição**. Recebe um áudio e devolve o texto com
**timestamps por palavra**, usando **faster-whisper (large-v3, pt-BR)**. O
device é resolvido por S.O.: **CUDA** em produção (Linux/NVIDIA), **CPU** no
macOS de dev (o backend CTranslate2 não suporta Metal/MPS).

Faz só isso, e é **assíncrono**: recebe o áudio, responde 202 na hora,
enfileira (uma transcrição por vez — GPU/CPU não é reentrante) e devolve o
resultado por **webhook**. Legenda `.ass`, moldura do template, render das
variantes, storage e status vivem no microserviço **`Video`** (Node/ffmpeg,
:8790), que é um dos que chamam este endpoint (o outro é o Laravel, direto).

> Não usa banco, MinIO nem disco persistente: o áudio vai pra um diretório
> temporário e é apagado ao fim do job.

## Contrato

```
GET  /health         → {status, model, device, language}

POST /transcriptions → 202 {job_id, status: "queued"}
     multipart {audio, uuid, webhook_url}
     → depois, POST {webhook_url} {uuid, status: done|failed, transcript?, error?}
       (header X-Observability-Token)
```

Formato do `transcript` no webhook (o `Video` consome `segments[].{start,end}`
e `segments[].words[].{word,start,end}`):

```jsonc
{
  "language": "pt",
  "segments": [
    {
      "start": 0.0, "end": 2.4, "text": "…",
      "words": [{"word": " Olá", "start": 0.0, "end": 0.31, "score": 0.98}]
    }
  ]
}
```

⚠️ O `start`/`end` de uma palavra **pode vir nulo** — é comportamento normal do
faster-whisper. Quem consome precisa preencher (o `SubtitleBuilder` do `Video`
faz isso, com golden test cobrindo o caso).

## GPU

O device é resolvido por S.O. (`app/pipeline/device.py`): **macOS → CPU/int8**
(CTranslate2 não tem Metal/MPS), **resto → o configurado** (`WHISPER_DEVICE`,
default `cuda`/`float16`, exige CUDA/Linux). O modelo fica carregado no processo
e um `threading.Lock` serializa as transcrições (a GPU não é reentrante). No
macOS sobe em CPU, mas é ordens de grandeza mais lento.

torch com CUDA é instalado **à parte**, antes do `requirements.txt`:

```bash
pip install torch --index-url https://download.pytorch.org/whl/cu124
```

## Rodar

```bash
cp .env.example .env
python3 -m venv .venv && .venv/bin/pip install -r requirements.txt
.venv/bin/python -m app.main
```

Sobe junto com o resto no `make up`.
