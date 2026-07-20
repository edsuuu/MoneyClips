# Transcriber

Microserviço de **transcrição**. Recebe um wav e devolve o texto com
**timestamps por palavra**, usando **faster-whisper (large-v3, pt-BR)** na
**GPU NVIDIA (CUDA)**.

Faz só isso. Legenda `.ass`, moldura do template, render das variantes,
storage, status e webhook vivem no microserviço **`Video`** (Node/ffmpeg,
:8790), que é quem chama este endpoint. Antes da fusão, tudo isso morava aqui.

> Não usa banco, MinIO nem disco: o áudio vai pra um diretório temporário e é
> apagado ao fim da requisição.

## Contrato

```
GET  /health      → {status, model, device, language}

POST /transcribe  → 200 {segments: [...], language}
     multipart {audio: <wav mono 16kHz>}   — SÍNCRONO
```

Formato da resposta (o `Video` consome `segments[].{start,end}` e
`segments[].words[].{word,start,end}`):

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

`WHISPER_DEVICE=cuda` exige CUDA/Linux; o modelo fica carregado no processo e
um `threading.Lock` serializa as requisições (a GPU não é reentrante). Em
máquinas sem CUDA, `WHISPER_DEVICE=cpu` + `WHISPER_COMPUTE_TYPE=int8` sobe, mas
é ordens de grandeza mais lento.

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
