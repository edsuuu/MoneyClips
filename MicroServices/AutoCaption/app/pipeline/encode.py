from __future__ import annotations

from app.config.settings import settings

_NVENC_HQ = [
    "-c:v", "h264_nvenc",
    "-preset", "p7",
    "-tune", "hq",
    "-rc", "vbr",
    "-cq", "16",
    "-b:v", "0",
    "-bf", "3",
    "-pix_fmt", "yuv420p",
]

_LIBX264_HQ = [
    "-c:v", "libx264",
    "-preset", "slower",
    "-crf", "16",
    "-pix_fmt", "yuv420p",
]


def video_args(force_cpu: bool = False) -> list[str]:
    if settings.gpu_encoder == "nvenc" and not force_cpu:
        return list(_NVENC_HQ)
    return list(_LIBX264_HQ)


def libx264_args() -> list[str]:
    return list(_LIBX264_HQ)
