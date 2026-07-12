from __future__ import annotations

import logging
import threading
import time
from datetime import datetime
from typing import Any
from zoneinfo import ZoneInfo

from app.pipeline.audio import extract_audio, probe_duration, probe_resolution
from app.pipeline.transcribe import transcribe
from app.pipeline.variants import (
    FILENAMES,
    JobOptions,
    generate_all,
    write_video_benchmark,
)
from app.storage.local import VideoStore

logger = logging.getLogger("autocaption.worker")

_TZ = ZoneInfo("America/Sao_Paulo")

# Serializa os jobs: WhisperX large-v3 + NVENC saturam a GPU, então processamos
# 1 vídeo por vez. Estado de progresso vive em status.json (fonte da verdade).
_gpu_lock = threading.Lock()

store = VideoStore()


def _now() -> str:
    return datetime.now(_TZ).isoformat()


def _set_status(uuid: str, status: str, step: str, **extra: Any) -> None:
    payload: dict[str, Any] = {
        "uuid": uuid,
        "status": status,
        "step": step,
        "updated_at": _now(),
    }
    payload.update(extra)
    store.write_status(uuid, payload)
    logger.info("[%s] %s / %s", uuid, status, step)


def _files(uuid: str) -> dict[str, bool]:
    d = store.video_dir(uuid)
    files = {
        "audio.wav": store.audio_path(uuid).exists(),
        "transcript.json": store.transcript_path(uuid).exists(),
    }
    for variant, name in FILENAMES.items():
        files[name] = (d / name).exists()
    return files


def _process(uuid: str, options: JobOptions) -> None:
    with _gpu_lock:
        try:
            source = store.find_source(uuid)
            if source is None:
                raise FileNotFoundError("source do vídeo não encontrado")

            aligned = None
            t_audio = 0.0
            t_transcribe = 0.0
            if options.with_captions:
                _set_status(uuid, "processing", "extracting_audio")
                t = time.perf_counter()
                audio = extract_audio(source, store.audio_path(uuid))
                t_audio = time.perf_counter() - t

                _set_status(uuid, "processing", "transcribing")
                t = time.perf_counter()
                aligned = transcribe(audio, store.transcript_path(uuid))
                t_transcribe = time.perf_counter() - t

            _set_status(uuid, "processing", "rendering_variants")
            timings = generate_all(uuid, aligned, source, store, options)

            # benchmark por vídeo (output_benchmark.txt na pasta do vídeo)
            sw, sh = probe_resolution(source)
            d = store.video_dir(uuid)
            sizes = {
                k: (d / FILENAMES[k]).stat().st_size / (1024 * 1024)
                for k in timings
                if (d / FILENAMES[k]).exists()
            }
            n_words = (
                sum(len(s.get("words", [])) for s in aligned.get("segments", []))
                if aligned else 0
            )
            write_video_benchmark(
                store, uuid,
                resolution=f"{sw}x{sh}",
                duration=probe_duration(source),
                n_words=n_words,
                t_audio=t_audio,
                t_transcribe=t_transcribe,
                variant_timings=timings,
                sizes=sizes,
            )

            _set_status(
                uuid, "done", "completed", files=_files(uuid), variant_seconds=timings
            )
        except Exception as exc:  # noqa: BLE001
            logger.exception("[%s] pipeline falhou", uuid)
            _set_status(uuid, "failed", "error", error=str(exc), files=_files(uuid))


def start_processing(uuid: str, options: JobOptions | None = None) -> None:
    """Dispara o pipeline num thread daemon e retorna na hora (202)."""
    opts = options or JobOptions()
    _set_status(uuid, "processing", "queued", options={
        "variants": opts.variants,
        "caption_position": opts.caption_position,
        "channel_name": opts.channel_name,
        "channel_handle": opts.channel_handle,
    })
    thread = threading.Thread(target=_process, args=(uuid, opts), daemon=True)
    thread.start()
