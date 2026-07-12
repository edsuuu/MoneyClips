from __future__ import annotations

import logging
import subprocess
from pathlib import Path

from app.config.settings import settings
from app.pipeline.encode import libx264_args, video_args

logger = logging.getLogger("autocaption.pipeline.reframe")

# Filtro: fundo = mesmo vídeo escalado pra cobrir 9:16 + desfoque; primeiro
# plano = vídeo original encaixado na largura, centralizado. Depois (opcional)
# queima o ASS.
_BASE = (
    "[0:v]split=2[bg][fg];"
    "[bg]scale={w}:{h}:force_original_aspect_ratio=increase,"
    "crop={w}:{h},gblur=sigma=22[bgb];"
    "[fg]scale={w}:{h}:force_original_aspect_ratio=decrease[fgs];"
    "[bgb][fgs]overlay=(W-w)/2:(H-h)/2"
)


def _filter(width: int, height: int, ass: Path | None) -> str:
    base = _BASE.format(w=width, h=height)
    if ass is None:
        return base + "[out]"
    return base + "[v];[v]ass=" + ass.name + "[out]"


def reframe_and_burn(
    source: Path,
    ass: Path | None,
    output: Path,
    width: int = 1080,
    height: int = 1920,
) -> Path:
    """Reenquadra o vídeo para width x height (default 9:16) com fundo desfocado
    e (se ass != None) queima a legenda — num passe de ffmpeg. cwd = pasta do
    output (nomes relativos evitam o escape do filtro `ass`)."""
    cwd = output.parent
    vf = _filter(width, height, ass)
    cmd = [
        "ffmpeg",
        "-y",
        "-hwaccel",
        "cuda",
        "-i",
        str(source),
        "-filter_complex",
        vf,
        "-map",
        "[out]",
        "-map",
        "0:a?",
        *video_args(),
        "-c:a",
        "copy",
        output.name,
    ]
    logger.info("reframe %dx%d + queima: %s", width, height, output.name)
    result = subprocess.run(  # noqa: S603
        cmd, cwd=str(cwd), capture_output=True, text=True, check=False
    )
    if result.returncode != 0 and settings.gpu_encoder == "nvenc":
        logger.warning("NVENC falhou no reframe, tentando libx264. %s", result.stderr[-1200:])
        cmd = [
            "ffmpeg", "-y", "-i", str(source), "-filter_complex", vf,
            "-map", "[out]", "-map", "0:a?",
            *libx264_args(),
            "-c:a", "copy", output.name,
        ]
        result = subprocess.run(  # noqa: S603
            cmd, cwd=str(cwd), capture_output=True, text=True, check=False
        )
    if result.returncode != 0:
        raise RuntimeError(f"ffmpeg falhou no reframe: {result.stderr[-2000:]}")
    return output
