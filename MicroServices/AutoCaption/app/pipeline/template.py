from __future__ import annotations

import logging
import random
import subprocess
from pathlib import Path
from typing import Any

from app.config.settings import settings
from app.pipeline.encode import libx264_args, video_args

logger = logging.getLogger("autocaption.pipeline.template")

CANVAS_W = 1080
CANVAS_H = 1920
# margem lateral do vídeo: bem fina (máx 3px) para vídeos horizontais (16:9),
# padrão maior para verticais/quadrados.
MARGIN_WIDE = 3
MARGIN_DEFAULT = 40
CORNER_RADIUS = 34  # cantos arredondados (só vídeos horizontais)
HEADER_BOTTOM = 460  # y onde termina o cabeçalho e começa a área do vídeo

LOGO_Y = 150  # cabeçalho um pouco mais baixo
LOGO_D = 168

# No template o vídeo entra pequeno; a legenda precisa de fonte proporcionalmente
# maior pra ficar tão destacada quanto no exemplo.
TEMPLATE_FONT_SCALE = 2.6

_BG = {"white": (255, 255, 255), "black": (0, 0, 0)}
_FG = {"white": (17, 17, 17), "black": (255, 255, 255)}
_MUTED = {"white": (110, 110, 115), "black": (170, 170, 175)}

_DEJAVU_BOLD = "/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf"
_DEJAVU = "/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf"


def _font(path: str, size: int) -> Any:
    from PIL import ImageFont

    return ImageFont.truetype(path, size)


def generate_random_logo(path: Path, initial: str = "U", size: int = 320) -> Path:
    """Gera uma logo circular aleatória (gradiente + inicial) se ainda não existir."""
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
    """Vídeo horizontal (16:9 etc). Verticais/9:16 retornam False."""
    return src_w >= src_h * 1.2


def compute_video_region(src_w: int, src_h: int) -> dict[str, int]:
    """Retângulo (x,y,w,h,round) onde o vídeo é colocado no canvas 9:16,
    centralizado verticalmente. Vídeos horizontais ganham margem fina (mais
    altos/visíveis) e cantos arredondados (round=1)."""
    wide = _is_wide(src_w, src_h)
    margin = MARGIN_WIDE if wide else MARGIN_DEFAULT
    w = CANVAS_W - 2 * margin
    h = 2 * round((w * src_h / src_w) / 2)
    # centraliza o vídeo verticalmente (zona segura da UI do TikTok). Não sobe
    # acima do cabeçalho.
    y = max(HEADER_BOTTOM, (CANVAS_H - h) // 2)
    return {"x": margin, "y": y, "w": w, "h": h, "round": int(wide)}


def _circular(img: Any, size: int) -> Any:
    """Recorta a imagem num círculo (avatar), independente de ser quadrada."""
    from PIL import Image, ImageDraw

    side = min(img.size)
    img = img.crop((0, 0, side, side)).resize((size, size)).convert("RGBA")
    mask = Image.new("L", (size, size), 0)
    ImageDraw.Draw(mask).ellipse((0, 0, size - 1, size - 1), fill=255)
    out = Image.new("RGBA", (size, size), (0, 0, 0, 0))
    out.paste(img, (0, 0), mask)
    return out


def render_static_layer(
    bg: str,
    out_png: Path,
    logo_path: Path,
    name: str | None = None,
    handle: str | None = None,
) -> Path:
    """Desenha o fundo (branco/preto) + cabeçalho: logo (circular) + @handle."""
    from PIL import Image, ImageDraw

    handle = handle if handle is not None else settings.channel_handle

    img = Image.new("RGB", (CANVAS_W, CANVAS_H), _BG[bg])
    draw = ImageDraw.Draw(img)

    logo = _circular(Image.open(logo_path).convert("RGBA"), LOGO_D)
    lx = (CANVAS_W - LOGO_D) // 2
    img.paste(logo, (lx, LOGO_Y), logo)

    handle_font = _font(_DEJAVU_BOLD, 46)
    b = draw.textbbox((0, 0), handle, font=handle_font)
    draw.text(
        ((CANVAS_W - (b[2] - b[0])) / 2 - b[0], LOGO_Y + LOGO_D + 26),
        handle, font=handle_font, fill=_FG[bg],
    )

    out_png.parent.mkdir(parents=True, exist_ok=True)
    img.save(out_png)
    return out_png


def _rounded_mask(w: int, h: int, radius: int, out_png: Path) -> Path:
    """Máscara (branco = visível) com cantos arredondados, tamanho do vídeo."""
    from PIL import Image, ImageDraw

    m = Image.new("L", (w, h), 0)
    ImageDraw.Draw(m).rounded_rectangle([0, 0, w - 1, h - 1], radius=radius, fill=255)
    out_png.parent.mkdir(parents=True, exist_ok=True)
    m.save(out_png)
    return out_png


def _build_filter(region: dict[str, int], ass: Path | None, position: str, rounded: bool) -> str:
    """Monta o filtergraph: escala o vídeo, arredonda (alphamerge com máscara),
    aplica legenda (dentro/embaixo) e sobrepõe no fundo estático."""
    w, h, x, y = region["w"], region["h"], region["x"], region["y"]
    parts = [f"[1:v]scale={w}:{h}[vs]"]
    vid = "[vs]"
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
) -> Path:
    """Compõe fundo estático (png) + vídeo. Se ass=None, sem legenda. Vídeos
    horizontais (region['round']) ganham cantos arredondados via máscara."""
    cwd = out.parent
    rounded = bool(region.get("round"))
    inputs = ["-loop", "1", "-i", static_png.name, "-i", str(source)]
    if rounded:
        mask = out.parent / f"mask_{region['w']}x{region['h']}.png"
        _rounded_mask(region["w"], region["h"], CORNER_RADIUS, mask)
        inputs += ["-loop", "1", "-i", mask.name]
    vf = _build_filter(region, ass, caption_position, rounded)

    def _cmd(enc: list[str]) -> list[str]:
        return [
            "ffmpeg", "-y", *inputs,
            "-filter_complex", vf,
            "-map", "[out]", "-map", "1:a?",
            *enc, "-c:a", "copy", "-shortest", out.name,
        ]

    logger.info("compondo template (%s, round=%s): %s", static_png.stem, rounded, out.name)
    result = subprocess.run(_cmd(video_args()), cwd=str(cwd), capture_output=True, text=True, check=False)  # noqa: S603
    if result.returncode != 0 and settings.gpu_encoder == "nvenc":
        logger.warning("NVENC falhou no template, tentando libx264. %s", result.stderr[-1000:])
        result = subprocess.run(_cmd(libx264_args()), cwd=str(cwd), capture_output=True, text=True, check=False)  # noqa: S603
    if result.returncode != 0:
        raise RuntimeError(f"ffmpeg falhou no template: {result.stderr[-2000:]}")
    return out
