from __future__ import annotations

import logging
import random
import subprocess
from pathlib import Path
from typing import Any

from app.config.settings import settings
from app.pipeline.audio import probe_duration
from app.pipeline.encode import libx264_args, video_args

logger = logging.getLogger("autocaption.pipeline.template")

CANVAS_W = 1080
CANVAS_H = 1920
MARGIN_WIDE = 3
MARGIN_DEFAULT = 40
CORNER_RADIUS = 34
HEADER_BOTTOM = 560

LOGO_X = 110
LOGO_Y = 170
LOGO_SIZE = 350
TEXT_X = 535
NAME_Y = 250
NAME_SIZE = 62
HANDLE_GAP = 20
HANDLE_SIZE = 42

TEMPLATE_FONT_SCALE = 2.6

WATERMARK_ALPHA = 110
WATERMARK_SIZE_RATIO = 0.075
WATERMARK_Y_RATIO = 0.8

_BG = {"white": (255, 255, 255), "black": (0, 0, 0)}
_FG = {"white": (17, 17, 17), "black": (255, 255, 255)}
_MUTED = {"white": (110, 110, 115), "black": (170, 170, 175)}

_DEJAVU_BOLD = "/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf"


def _font(path: str, size: int) -> Any:
    from PIL import ImageFont

    return ImageFont.truetype(path, size)


def generate_random_logo(path: Path, initial: str = "U", size: int = 320) -> Path:
    if path.exists():
        return path
    from PIL import Image, ImageDraw

    path.parent.mkdir(parents=True, exist_ok=True)
    rng = random.Random()
    c1 = tuple(rng.randint(40, 200) for _ in range(3))
    c2 = tuple(rng.randint(40, 220) for _ in range(3))

    img = Image.new("RGBA", (size, size), (0, 0, 0, 0))
    grad = Image.new("RGB", (size, size))
    px = grad.load()
    for y in range(size):
        t = y / size
        px_row = tuple(round(c1[i] + (c2[i] - c1[i]) * t) for i in range(3))
        for x in range(size):
            px[x, y] = px_row
    mask = Image.new("L", (size, size), 0)
    ImageDraw.Draw(mask).ellipse((0, 0, size - 1, size - 1), fill=255)
    img.paste(grad, (0, 0), mask)

    draw = ImageDraw.Draw(img)
    draw.ellipse((0, 0, size - 1, size - 1), outline=(255, 255, 255, 230), width=8)
    font = _font(_DEJAVU_BOLD, int(size * 0.5))
    tb = draw.textbbox((0, 0), initial, font=font)
    tw, th = tb[2] - tb[0], tb[3] - tb[1]
    draw.text(
        ((size - tw) / 2 - tb[0], (size - th) / 2 - tb[1]),
        initial,
        font=font,
        fill=(255, 255, 255, 255),
    )
    img.save(path)
    logger.info("logo aleatória gerada: %s", path)
    return path


def _is_wide(src_w: int, src_h: int) -> bool:
    return src_w >= src_h * 1.2


def compute_video_region(src_w: int, src_h: int) -> dict[str, int]:
    wide = _is_wide(src_w, src_h)
    margin = MARGIN_WIDE if wide else MARGIN_DEFAULT
    w = CANVAS_W - 2 * margin
    h = 2 * round((w * src_h / src_w) / 2)
    y = max(HEADER_BOTTOM, (CANVAS_H - h) // 2)
    return {"x": margin, "y": y, "w": w, "h": h, "round": int(wide)}


def render_static_layer(
    bg: str,
    out_png: Path,
    logo_path: Path,
    name: str | None = None,
    handle: str | None = None,
) -> Path:
    from PIL import Image, ImageDraw

    name = name if name is not None and name != "" else settings.channel_name
    handle = handle if handle is not None and handle != "" else settings.channel_handle

    img = Image.new("RGB", (CANVAS_W, CANVAS_H), _BG[bg])
    draw = ImageDraw.Draw(img)

    logo = Image.open(logo_path).convert("RGBA")
    side = min(logo.size)
    logo = logo.crop((0, 0, side, side)).resize((LOGO_SIZE, LOGO_SIZE))
    if bg == "white":
        pad = 18
        draw.rounded_rectangle(
            [LOGO_X - pad, LOGO_Y - pad, LOGO_X + LOGO_SIZE + pad, LOGO_Y + LOGO_SIZE + pad],
            radius=28, fill=(0, 0, 0),
        )
    img.paste(logo, (LOGO_X, LOGO_Y), logo)

    font_path = settings.template_font_path or _DEJAVU_BOLD
    name_font = _font(font_path, NAME_SIZE)
    handle_font = _font(font_path, HANDLE_SIZE)
    draw.text((TEXT_X, NAME_Y), name, font=name_font, fill=_FG[bg])
    nb = draw.textbbox((TEXT_X, NAME_Y), name, font=name_font)
    draw.text((TEXT_X, nb[3] + HANDLE_GAP), handle, font=handle_font, fill=_MUTED[bg])

    out_png.parent.mkdir(parents=True, exist_ok=True)
    img.save(out_png)
    return out_png


def render_watermark(text: str, region_w: int, out_png: Path) -> Path:
    from PIL import Image, ImageDraw

    size = max(28, round(region_w * WATERMARK_SIZE_RATIO))
    font = _font(settings.template_font_path or _DEJAVU_BOLD, size)
    probe = ImageDraw.Draw(Image.new("RGBA", (1, 1)))
    b = probe.textbbox((0, 0), text, font=font)
    tw, th = b[2] - b[0], b[3] - b[1]
    pad = round(size * 0.25)
    img = Image.new("RGBA", (tw + 2 * pad, th + 2 * pad), (0, 0, 0, 0))
    draw = ImageDraw.Draw(img)
    draw.text(
        (pad - b[0], pad - b[1]),
        text,
        font=font,
        fill=(255, 255, 255, WATERMARK_ALPHA),
        stroke_width=max(1, size // 28),
        stroke_fill=(0, 0, 0, round(WATERMARK_ALPHA * 0.55)),
    )
    out_png.parent.mkdir(parents=True, exist_ok=True)
    img.save(out_png)
    return out_png


def _rounded_mask(w: int, h: int, radius: int, out_png: Path) -> Path:
    from PIL import Image, ImageDraw

    m = Image.new("L", (w, h), 0)
    ImageDraw.Draw(m).rounded_rectangle([0, 0, w - 1, h - 1], radius=radius, fill=255)
    out_png.parent.mkdir(parents=True, exist_ok=True)
    m.save(out_png)
    return out_png


def _build_filter(
    region: dict[str, int],
    ass: Path | None,
    position: str,
    rounded: bool,
    watermark_idx: int | None = None,
) -> str:
    w, h, x, y = region["w"], region["h"], region["x"], region["y"]
    parts = [f"[1:v]scale={w}:{h}[vs]"]
    vid = "[vs]"
    if watermark_idx is not None:
        parts.append(f"{vid}[{watermark_idx}:v]overlay=(W-w)/2:H*{WATERMARK_Y_RATIO}-h/2[vwm]")
        vid = "[vwm]"
    if rounded:
        parts.append(f"{vid}[2:v]alphamerge[vm]")
        vid = "[vm]"
    if ass is not None and position != "below":
        parts.append(f"{vid}ass={ass.name}[va]")
        vid = "[va]"
    ov = f"[0:v]{vid}overlay={x}:{y}:shortest=1"
    if ass is not None and position == "below":
        parts.append(ov + "[comp]")
        parts.append(f"[comp]ass={ass.name}[out]")
    else:
        parts.append(ov + "[out]")
    return ";".join(parts)


def compose_template(
    source: Path,
    ass: Path | None,
    static_png: Path,
    region: dict[str, int],
    out: Path,
    caption_position: str = "inside",
    watermark_text: str | None = None,
) -> Path:
    cwd = out.parent
    rounded = bool(region.get("round"))
    inputs = ["-loop", "1", "-i", static_png.name, "-i", str(source)]
    if rounded:
        mask = out.parent / f"mask_{region['w']}x{region['h']}.png"
        _rounded_mask(region["w"], region["h"], CORNER_RADIUS, mask)
        inputs += ["-loop", "1", "-i", mask.name]
    watermark_idx = None
    text = (settings.watermark_text if watermark_text is None else watermark_text).strip()
    if text:
        wm = out.parent / "watermark.png"
        render_watermark(text, region["w"], wm)
        watermark_idx = 3 if rounded else 2
        inputs += ["-loop", "1", "-i", wm.name]
    vf = _build_filter(region, ass, caption_position, rounded, watermark_idx)
    duration = probe_duration(source)
    cap = ["-t", f"{duration:.3f}"] if duration > 0 else []

    def _cmd(enc: list[str]) -> list[str]:
        return [
            "ffmpeg", "-y", *inputs,
            "-filter_complex", vf,
            "-map", "[out]", "-map", "1:a?",
            *enc, "-c:a", "copy", *cap, "-shortest", out.name,
        ]

    logger.info("compondo template (%s, round=%s): %s", static_png.stem, rounded, out.name)
    result = subprocess.run(_cmd(video_args()), cwd=str(cwd), capture_output=True, text=True, check=False)  # noqa: S603
    if result.returncode != 0 and settings.gpu_encoder == "nvenc":
        logger.warning("NVENC falhou no template, tentando libx264. %s", result.stderr[-1000:])
        result = subprocess.run(_cmd(libx264_args()), cwd=str(cwd), capture_output=True, text=True, check=False)  # noqa: S603
    if result.returncode != 0:
        raise RuntimeError(f"ffmpeg falhou no template: {result.stderr[-2000:]}")
    return out
