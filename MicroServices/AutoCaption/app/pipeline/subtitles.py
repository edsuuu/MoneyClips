from __future__ import annotations

import logging
from dataclasses import dataclass
from pathlib import Path
from typing import Any

from app.config.settings import settings

logger = logging.getLogger("autocaption.pipeline.subtitles")

WHITE = "&H00FFFFFF"


def _tag(color: str) -> str:
    color = color.strip()
    if not color.endswith("&"):
        color += "&"
    return color


def _style_color(color: str) -> str:
    return color.strip().rstrip("&")


@dataclass
class Word:
    text: str
    start: float
    end: float


@dataclass
class Line:
    words: list[Word]

    @property
    def start(self) -> float:
        return self.words[0].start

    @property
    def end(self) -> float:
        return self.words[-1].end

    @property
    def text(self) -> str:
        return " ".join(w.text for w in self.words)


def _collect_words(aligned: dict[str, Any]) -> list[Word]:
    raw: list[dict[str, Any]] = []
    for seg in aligned.get("segments", []):
        seg_start = float(seg.get("start", 0.0))
        seg_end = float(seg.get("end", seg_start))
        words = seg.get("words") or []
        for w in words:
            raw.append({"word": w, "seg_start": seg_start, "seg_end": seg_end})

    result: list[Word] = []
    for i, item in enumerate(raw):
        w = item["word"]
        text = str(w.get("word", "")).strip().lstrip(",.;:!?-–— ")
        if not text:
            continue
        start = w.get("start")
        end = w.get("end")
        if start is None:
            start = result[-1].end if result else item["seg_start"]
        if end is None:
            end = None
            for nxt in raw[i + 1 :]:
                nxt_start = nxt["word"].get("start")
                if nxt_start is not None:
                    end = float(nxt_start)
                    break
            if end is None:
                end = item["seg_end"]
        start = float(start)
        end = max(float(end), start + 0.05)
        result.append(Word(text=text, start=start, end=end))
    return result


def _build_lines(aligned: dict[str, Any]) -> list[Line]:
    max_words = settings.max_words_per_line
    max_dur = settings.max_line_duration
    lines: list[Line] = []

    for seg in aligned.get("segments", []):
        seg_words = _collect_words({"segments": [seg]})
        current: list[Word] = []
        for word in seg_words:
            if current and (
                len(current) >= max_words or (word.end - current[0].start) > max_dur
            ):
                lines.append(Line(words=current))
                current = []
            current.append(word)
        if current:
            lines.append(Line(words=current))
    return lines


def _fmt_srt_time(seconds: float) -> str:
    ms = int(round(seconds * 1000))
    h, ms = divmod(ms, 3_600_000)
    m, ms = divmod(ms, 60_000)
    s, ms = divmod(ms, 1000)
    return f"{h:02d}:{m:02d}:{s:02d},{ms:03d}"


def _fmt_ass_time(seconds: float) -> str:
    cs = int(round(seconds * 100))
    h, cs = divmod(cs, 360_000)
    m, cs = divmod(cs, 6000)
    s, cs = divmod(cs, 100)
    return f"{h:d}:{m:02d}:{s:02d}.{cs:02d}"


def write_srt(lines: list[Line], out: Path) -> Path:
    blocks: list[str] = []
    for i, line in enumerate(lines, start=1):
        blocks.append(
            f"{i}\n{_fmt_srt_time(line.start)} --> {_fmt_srt_time(line.end)}\n"
            f"{line.text.upper()}\n"
        )
    out.write_text("\n".join(blocks), encoding="utf-8")
    return out


_REF_W = 1080
_REF_H = 1920


def _ass_header(
    width: int,
    height: int,
    font_scale: float = 1.0,
    alignment: int = 2,
    margin_v_override: int | None = None,
) -> str:
    highlight = _style_color(settings.highlight_color)
    scale_v = height / _REF_H
    scale_h = width / _REF_W
    fontsize = max(12, round(settings.font_size * 4 * scale_v * font_scale))
    outline = max(1, round(4 * scale_v * font_scale))
    shadow = max(0, round(2 * scale_v))
    margin_v = margin_v_override if margin_v_override is not None else max(10, round(180 * scale_v))
    margin_lr = max(10, round(40 * scale_h))
    return f"""[Script Info]
ScriptType: v4.00+
PlayResX: {width}
PlayResY: {height}
WrapStyle: 0
ScaledBorderAndShadow: yes

[V4+ Styles]
Format: Name, Fontname, Fontsize, PrimaryColour, SecondaryColour, OutlineColour, BackColour, Bold, Italic, Underline, StrikeOut, ScaleX, ScaleY, Spacing, Angle, BorderStyle, Outline, Shadow, Alignment, MarginL, MarginR, MarginV, Encoding
Style: Default,{settings.font_name},{fontsize},{WHITE},{highlight},&H00000000,&H64000000,-1,-1,0,0,100,100,0,0,1,{outline},{shadow},{alignment},{margin_lr},{margin_lr},{margin_v},1

[Events]
Format: Layer, Start, End, Style, Name, MarginL, MarginR, MarginV, Effect, Text
"""


def _line_text_with_highlight(line: Line, active_index: int) -> str:
    hl = _tag(settings.highlight_color)
    white = _tag(WHITE)
    parts: list[str] = []
    for idx, word in enumerate(line.words):
        token = word.text.upper()
        if idx == active_index:
            parts.append(f"{{\\c{hl}}}{token}{{\\c{white}}}")
        elif settings.hide_future_words and idx > active_index:
            parts.append(f"{{\\alpha&HFF&}}{token}{{\\alpha&H00&}}")
        else:
            parts.append(token)
    return " ".join(parts)


def write_ass(
    lines: list[Line],
    out: Path,
    width: int,
    height: int,
    font_scale: float = 1.0,
    alignment: int = 2,
    margin_v_override: int | None = None,
    offset: float | None = None,
) -> Path:
    offset = settings.subtitle_offset if offset is None else offset
    events: list[str] = []
    for line in lines:
        for idx, word in enumerate(line.words):
            start = max(0.0, word.start + offset)
            raw_end = line.words[idx + 1].start if idx + 1 < len(line.words) else word.end
            end = max(0.0, raw_end + offset)
            end = max(end, start + 0.05)
            text = _line_text_with_highlight(line, idx)
            events.append(
                f"Dialogue: 0,{_fmt_ass_time(start)},{_fmt_ass_time(end)},"
                f"Default,,0,0,0,,{text}"
            )
    out.write_text(
        _ass_header(width, height, font_scale, alignment, margin_v_override)
        + "\n".join(events)
        + "\n",
        encoding="utf-8",
    )
    return out


def build_subtitles(
    aligned: dict[str, Any],
    srt_out: Path,
    ass_out: Path,
    width: int,
    height: int,
    font_scale: float = 1.0,
    alignment: int = 2,
    margin_v_override: int | None = None,
    offset: float | None = None,
) -> None:
    lines = _build_lines(aligned)
    logger.info("gerando legendas: %d linhas (%dx%d)", len(lines), width, height)
    write_srt(lines, srt_out)
    write_ass(lines, ass_out, width, height, font_scale, alignment, margin_v_override, offset)
