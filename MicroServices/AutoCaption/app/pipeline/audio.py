from __future__ import annotations

import json
import logging
import subprocess
from pathlib import Path

logger = logging.getLogger("autocaption.pipeline.audio")


def probe_duration(source: Path) -> float:
    """Duração do vídeo em segundos (ffprobe)."""
    cmd = [
        "ffprobe", "-v", "error", "-show_entries", "format=duration",
        "-of", "default=nk=1:nw=1", str(source),
    ]
    result = subprocess.run(cmd, capture_output=True, text=True, check=False)  # noqa: S603
    try:
        return float(result.stdout.strip())
    except ValueError:
        return 0.0


def probe_resolution(source: Path) -> tuple[int, int]:
    """Retorna (width, height) do primeiro stream de vídeo via ffprobe."""
    cmd = [
        "ffprobe",
        "-v",
        "error",
        "-select_streams",
        "v:0",
        "-show_entries",
        "stream=width,height",
        "-of",
        "json",
        str(source),
    ]
    result = subprocess.run(cmd, capture_output=True, text=True, check=False)  # noqa: S603
    if result.returncode != 0:
        raise RuntimeError(f"ffprobe falhou ao ler resolução: {result.stderr[-1000:]}")
    stream = json.loads(result.stdout)["streams"][0]
    return int(stream["width"]), int(stream["height"])


def extract_audio(source: Path, dest: Path) -> Path:
    """Extrai o áudio do vídeo em WAV PCM 16kHz mono — formato ideal do Whisper.

    Roda em CPU (é leve: só demux + resample do áudio).
    """
    cmd = [
        "ffmpeg",
        "-y",
        "-i",
        str(source),
        "-vn",
        "-ac",
        "1",
        "-ar",
        "16000",
        "-c:a",
        "pcm_s16le",
        str(dest),
    ]
    logger.info("extraindo áudio: %s -> %s", source.name, dest.name)
    result = subprocess.run(cmd, capture_output=True, text=True, check=False)  # noqa: S603
    if result.returncode != 0:
        raise RuntimeError(f"ffmpeg falhou ao extrair áudio: {result.stderr[-2000:]}")
    return dest
