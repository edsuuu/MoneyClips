from __future__ import annotations

import logging
import time
from dataclasses import dataclass, field
from pathlib import Path

from app.config.settings import settings
from app.pipeline.audio import probe_resolution
from app.pipeline.burn import burn_subtitles
from app.pipeline.reframe import reframe_and_burn
from app.pipeline.subtitles import build_subtitles
from app.pipeline.template import (
    TEMPLATE_FONT_SCALE,
    compose_template,
    compute_video_region,
    generate_random_logo,
    render_static_layer,
)
from app.storage.local import VideoStore

logger = logging.getLogger("autocaption.pipeline.variants")

ALL_VARIANTS = ("original", "vertical", "template_white", "template_black")

FILENAMES = {
    "original": "original.mp4",
    "vertical": "vertical_916.mp4",
    "template_white": "template_white.mp4",
    "template_black": "template_black.mp4",
}

BELOW_FONT_SCALE = 1.9
BELOW_GAP = 120

VERTICAL_FONT_SCALE = 1.5
VERTICAL_INSET = 90


def _default_variants() -> list[str]:
    return [v.strip() for v in settings.output_variants.split(",") if v.strip()]


BENCHMARK_FILE = "output_benchmark.txt"


def write_video_benchmark(
    store: VideoStore,
    label: str,
    *,
    resolution: str,
    duration: float,
    n_words: int,
    t_audio: float,
    t_transcribe: float,
    variant_timings: dict[str, float],
    sizes: dict[str, float],
) -> Path:
    def rtf(v: float) -> str:
        return f"RTF {v / duration:.2f}" if duration else "RTF —"

    lines = [
        f"AutoCaption — benchmark: {label}",
        f"resolução: {resolution}  |  duração: {duration:.1f}s  |  palavras: {n_words}",
        f"transcrição: faster-whisper {settings.whisper_model} ({settings.whisper_language})",
        f"encoder: {settings.gpu_encoder} (qualidade máxima) | fonte: {settings.font_name}",
        "",
        f"{'extração de áudio':22}: {t_audio:6.1f}s  ({rtf(t_audio)})",
        f"{'transcrição':22}: {t_transcribe:6.1f}s  ({rtf(t_transcribe)})",
    ]
    for k in ALL_VARIANTS:
        v = variant_timings.get(k)
        if v is not None:
            mb = sizes.get(k, 0.0)
            lines.append(f"{'variante ' + k:22}: {v:6.1f}s  ({rtf(v)})  |  {mb:.1f} MB")
    total = t_audio + t_transcribe + sum(variant_timings.values())
    lines.append("")
    lines.append(f"{'TOTAL':22}: {total:6.1f}s  ({rtf(total)})")
    out = store.video_dir(label) / BENCHMARK_FILE
    out.write_text("\n".join(lines) + "\n", encoding="utf-8")
    return out


@dataclass
class JobOptions:
    variants: list[str] = field(default_factory=_default_variants)
    caption_position: str = "below"
    channel_name: str = ""
    channel_handle: str = ""
    subtitle_offset: float | None = None
    with_captions: bool = True
    watermark_text: str | None = None

    def __post_init__(self) -> None:
        if not self.channel_name:
            self.channel_name = settings.channel_name
        if not self.channel_handle:
            self.channel_handle = settings.channel_handle
        if self.watermark_text is None:
            self.watermark_text = settings.watermark_text
        self.variants = [v for v in self.variants if v in ALL_VARIANTS] or _default_variants()


def generate_all(
    uid: str,
    aligned: dict | None,
    source: Path,
    store: VideoStore,
    options: JobOptions | None = None,
) -> dict[str, float]:
    opts = options or JobOptions()
    captions = opts.with_captions and aligned is not None
    sw, sh = probe_resolution(source)
    d = store.video_dir(uid)
    logo = generate_random_logo(
        Path(settings.channel_logo).resolve(),
        (opts.channel_name.strip()[:1] or "U").upper(),
    )
    timings: dict[str, float] = {}

    def _template(bg: str, name: str) -> None:
        region = compute_video_region(sw, sh)
        png = d / f"layer_{bg}.png"
        render_static_layer(bg, png, logo, opts.channel_name, opts.channel_handle)
        ass: Path | None = None
        if captions:
            ass = d / f"subs_tpl_{bg}.ass"
            if opts.caption_position == "below":
                margin_v = region["y"] + region["h"] + BELOW_GAP
                build_subtitles(
                    aligned, d / f"subs_tpl_{bg}.srt", ass, 1080, 1920,
                    font_scale=BELOW_FONT_SCALE, alignment=8, margin_v_override=margin_v,
                    offset=opts.subtitle_offset,
                )
            else:
                build_subtitles(
                    aligned, d / f"subs_tpl_{bg}.srt", ass, region["w"], region["h"],
                    font_scale=TEMPLATE_FONT_SCALE, offset=opts.subtitle_offset,
                )
        compose_template(source, ass, png, region, d / name, opts.caption_position, opts.watermark_text)

    def _run(name: str) -> None:
        start = time.perf_counter()
        if name == "original":
            ass = None
            if captions:
                ass = d / "subs_original.ass"
                build_subtitles(
                    aligned, d / "subs_original.srt", ass, sw, sh, offset=opts.subtitle_offset
                )
            burn_subtitles(source, ass, d / FILENAMES[name])
        elif name == "vertical":
            ass = None
            if captions:
                ass = d / "subs_916.ass"
                scale = min(1080 / sw, 1920 / sh)
                vh = round(sh * scale)
                band_bottom = (1920 + vh) // 2
                v_margin = max(120, 1920 - band_bottom + VERTICAL_INSET)
                build_subtitles(
                    aligned, d / "subs_916.srt", ass, 1080, 1920,
                    font_scale=VERTICAL_FONT_SCALE, margin_v_override=v_margin,
                    offset=opts.subtitle_offset,
                )
            reframe_and_burn(source, ass, d / FILENAMES[name], 1080, 1920)
        elif name == "template_white":
            _template("white", FILENAMES[name])
        elif name == "template_black":
            _template("black", FILENAMES[name])
        else:
            logger.warning("variante desconhecida ignorada: %s", name)
            return
        timings[name] = time.perf_counter() - start
        logger.info("[%s] variante %s: %.1fs", uid, name, timings[name])

    for name in opts.variants:
        _run(name)
    return timings
