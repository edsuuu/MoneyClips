# Pendências para o próximo agente

## Codec de vídeo no download

### Contexto
O `Downloader` (`app/Services/VideoProcessor/Downloader.php`) baixa vídeo e áudio via `yt-dlp` e salva em `storage/app/private/videos/{video_id}/`.

O formato atual é:
```
bestvideo[ext=mp4]+bestaudio[ext=m4a]/bestvideo+bestaudio/best[ext=mp4]/best
```

O `yt-dlp` escolhe automaticamente o melhor codec disponível, o que pode resultar em arquivos com codec `VP9`, `AV1` ou `H.265` — codecs que nem sempre são suportados em players web (especialmente Safari) e podem causar problemas no pipeline de transcrição/corte.

### O que precisa ser feito

No método `runDownload()` do `Downloader`, adicionar a restrição de codec para garantir que o vídeo baixado use **H.264 (avc1)**, que tem compatibilidade universal:

```php
// Formato atual
public const string FORMAT_VIDEO = 'bestvideo[ext=mp4]+bestaudio[ext=m4a]/bestvideo+bestaudio/best[ext=mp4]/best';

// Formato desejado — prioriza H.264, cai para melhor disponível se não houver
public const string FORMAT_VIDEO = 'bestvideo[vcodec^=avc1][ext=mp4]+bestaudio[ext=m4a]/bestvideo[ext=mp4]+bestaudio[ext=m4a]/bestvideo+bestaudio/best[ext=mp4]/best';
```

Além disso, avaliar se vale passar `--postprocessor-args` para o `ffmpeg` forçar re-encode em H.264 caso o stream não seja compatível:
```
--postprocessor-args "ffmpeg:-c:v libx264 -crf 23 -preset fast -c:a aac"
```

### Arquivos relevantes
- `app/Services/VideoProcessor/Downloader.php` — constante `FORMAT_VIDEO` e método `runDownload()`
- `app/Jobs/ProcessVideoJob.php` — chama `downloadVideo()` e `downloadAudio()`

### Observação
O áudio (`FORMAT_AUDIO = 'bestaudio[ext=m4a]/bestaudio'`) já está bem definido e não precisa de ajuste de codec.

---

## Divergências entre Laravel e microserviço Python (`~/projects/autopost`)

Comparação feita entre `app/Services/VideoProcessor/Uploader.php` (Laravel) e `app/pipeline/workflows/video.py` (Python).

### 1. Estrutura de path no MinIO — DIVERGENTE ⚠️

| | Laravel (`Uploader.php`) | Python (`video.py`) |
|---|---|---|
| Vídeo original | `videos/{uuid}/{type}.{ext}` | `videos/{video_id}/original/source{ext}` |
| Áudio | `videos/{uuid}/audio.m4a` | `videos/{video_id}/audio/source.wav` |
| Thumbnail | `videos/{uuid}/thumbnail.jpg` | `videos/{video_id}/thumbnail/cover.jpg` |
| HLS | `videos/{uuid}/hls/...` | `videos/{video_id}/hls/{relative_path}` |

**Problema**: O Laravel usa `{uuid}` e salva sem subpasta (`original.mp4`), enquanto o Python usa `{video_id}` e salva em subpasta (`original/source.mp4`). Se os dois serviços precisarem compartilhar arquivos, os paths não vão bater.

**O que decidir**: padronizar para um dos dois formatos. Sugestão: adotar o padrão do Python (`videos/{uuid}/original/source.mp4`) pois tem subpastas limpas e já está em produção. Ajustar `Uploader::upload()`:
```php
$remotePath = "videos/{$video->uuid}/{$type}/source.{$extension}";
```

### 2. Tipos de arquivo — COMPATÍVEIS ✅

Ambos usam as mesmas strings:

| Tipo | Laravel (migration) | Python |
|---|---|---|
| Vídeo original | `original` | `original` |
| Áudio | `audio` | `audio` |
| Thumbnail | `thumbnail` | `thumbnail` |
| Vídeo legendado | `legendado` | `legendado` |
| Cortes | `pt1`, `pt2`... | `pt{index}` |
| HLS | — | `hls_master`, `hls_asset` |

### 3. Bucket — COMPATÍVEL ✅

Ambos usam `auto-post`.
- Python: `minio_bucket: str = "auto-post"` (`app/support/config.py`)
- Laravel: `AWS_BUCKET=auto-post` (`.env`)

### 4. Nome do disco na tabela `files` — DIVERGENTE ⚠️

| | Valor |
|---|---|
| Python (`StoredFile.disk`) | `"minio"` |
| Laravel (`files.disk` default) | `"s3"` (alterado recentemente) |

**Problema**: se o Python salvar um arquivo com `disk = "minio"` e o Laravel tentar ler com `Storage::disk($file->disk)`, vai buscar no disco `"minio"` — que foi removido do `filesystems.php`. Decidir se mantém o disco `minio` como alias do `s3` ou se unifica o nome.

### Arquivos relevantes
- `app/Services/VideoProcessor/Uploader.php` — path e disk do upload
- `database/migrations/2026_05_24_170008_create_files_table.php` — default do disk
- `config/filesystems.php` — configuração dos discos
- `~/projects/autopost/app/pipeline/workflows/video.py` — pipeline Python
- `~/projects/autopost/app/storage/base.py` — `StoredFile` dataclass
- `~/projects/autopost/app/support/config.py` — config MinIO do Python
