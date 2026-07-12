from __future__ import annotations

import logging
import subprocess
from pathlib import Path

from app.config.settings import settings
from app.pipeline.encode import libx264_args, video_args

logger = logging.getLogger("autocaption.pipeline.burn")


def _run(cmd: list[str], cwd: Path) -> subprocess.CompletedProcess[str]:
    return subprocess.run(  # noqa: S603
        cmd, cwd=str(cwd), capture_output=True, text=True, check=False
    )


def _vf(ass_name: str | None) -> list[str]:
    return ["-vf", f"ass={ass_name}"] if ass_name else []


def _nvenc_cmd(source_name: str, ass_name: str | None, output_name: str) -> list[str]:
    # -hwaccel cuda: decode na GPU (frames voltam pra RAM p/ o libass renderizar
    # o texto) e h264_nvenc encoda de volta na GPU. O libass roda em CPU (rápido).
    return [
        "ffmpeg", "-y", "-hwaccel", "cuda",
        "-i", source_name,
        *_vf(ass_name),
        *video_args(),
        "-c:a", "copy",
        output_name,
    ]


def _libx264_cmd(source_name: str, ass_name: str | None, output_name: str) -> list[str]:
    return [
        "ffmpeg", "-y",
        "-i", source_name,
        *_vf(ass_name),
        *libx264_args(),
        "-c:a", "copy",
        output_name,
    ]


def burn_subtitles(source: Path, ass: Path | None, output: Path) -> Path:
    """Queima a legenda ASS no vídeo (ou só re-encoda em alta qualidade se
    ass=None). NVENC (GPU) com fallback libx264 (CPU).

    Roda com cwd = pasta do vídeo e nomes relativos pra evitar as regras de
    escape do filtro `ass` do ffmpeg com paths absolutos.
    """
    cwd = output.parent
    source_name = source.name
    ass_name = ass.name if ass is not None else None
    output_name = output.name

    if settings.gpu_encoder == "nvenc":
        logger.info("queimando legenda com h264_nvenc (GPU): %s", output_name)
        result = _run(_nvenc_cmd(source_name, ass_name, output_name), cwd)
        if result.returncode == 0:
            return output
        logger.warning(
            "NVENC falhou (%s), caindo pra libx264 (CPU). stderr: %s",
            result.returncode,
            result.stderr[-1500:],
        )

    logger.info("queimando legenda com libx264 (CPU): %s", output_name)
    result = _run(_libx264_cmd(source_name, ass_name, output_name), cwd)
    if result.returncode != 0:
        raise RuntimeError(f"ffmpeg falhou ao queimar legenda: {result.stderr[-2000:]}")
    return output
