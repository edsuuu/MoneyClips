from __future__ import annotations

import logging
import re
from collections.abc import Callable
from dataclasses import dataclass
from pathlib import Path
from typing import Any
from urllib.parse import parse_qs, urlparse

import yt_dlp

from app.config.settings import settings

logger = logging.getLogger("shorts.youtube")


def _video_encoder_args() -> list[str]:
    encoder = settings.gpu_encoder
    if encoder == "nvenc":
        return ["-c:v", "h264_nvenc", "-preset", "p5", "-rc", "vbr", "-cq", "20", "-c:a", "copy"]
    if encoder == "videotoolbox":
        return ["-c:v", "h264_videotoolbox", "-b:v", "12M", "-c:a", "copy"]
    return ["-c:v", "libx264", "-preset", "slow", "-crf", "18", "-c:a", "copy"]


class VideoUnavailableError(Exception):
    pass


@dataclass(frozen=True)
class ShortVideo:
    youtube_id: str
    download_url: str
    title: str
    description: str
    hashtags: list[str]


@dataclass(frozen=True)
class VideoMetadata:
    youtube_id: str
    title: str
    duration_seconds: int | None
    width: int | None
    height: int | None
    channel: str | None
    thumbnail_url: str | None
    is_live: bool


@dataclass(frozen=True)
class DownloadedVideo:
    path: Path
    title: str
    duration_seconds: int | None
    width: int | None
    height: int | None


def normalize_shorts_url(channel_url: str) -> str:
    url = channel_url.strip().rstrip("/")
    return url if url.endswith("/shorts") else f"{url}/shorts"


def parse_hashtags(text: str) -> list[str]:
    return list(dict.fromkeys(re.findall(r"#[\wÀ-ÿ]+", text, flags=re.UNICODE)))


def extract_video_id(value: str) -> str | None:
    parsed = urlparse(value)
    if parsed.hostname == "youtu.be":
        return parsed.path.strip("/").split("/")[0] or None
    if parsed.hostname and "youtube" in parsed.hostname:
        query = parse_qs(parsed.query)
        if query.get("v"):
            return query["v"][0]
        match = re.match(r"^/(?:shorts|embed|live)/([^/?]+)", parsed.path)
        if match:
            return match.group(1)
    if re.fullmatch(r"[A-Za-z0-9_-]{6,}", value):
        return value
    return None


_PT_BR_HEADERS = {
    "Accept-Language": "pt-BR,pt;q=0.9,en;q=0.5",
}

_YOUTUBE_PORTUGUESE_EXTRACTOR_ARGS = {
    # Pede o título/descrição localizados em português ao YouTube. Sem isso,
    # vídeos com legenda automática em vários idiomas voltam com o título
    # "original" (frequentemente em inglês), mesmo quando o canal é brasileiro.
    # ATENÇÃO: o yt-dlp valida esse `lang` contra a lista de códigos suportados —
    # o português brasileiro é `pt` (NÃO `pt-BR`, que dá "Unsupported language
    # code"; `pt-PT` é Portugal). O Accept-Language do header pode ser pt-BR.
    "youtube": {"lang": ["pt"]},
}


def list_shorts(channel_url: str) -> list[ShortVideo]:
    shorts_url = normalize_shorts_url(channel_url)
    options = {
        "quiet": True,
        "no_warnings": True,
        "extract_flat": True,
        "ignoreerrors": True,
        "http_headers": _PT_BR_HEADERS,
        "extractor_args": _YOUTUBE_PORTUGUESE_EXTRACTOR_ARGS,
    }

    with yt_dlp.YoutubeDL(options) as ydl:
        info = ydl.extract_info(shorts_url, download=False)

    entries = list((info or {}).get("entries") or [])
    videos: list[ShortVideo] = []
    seen: set[str] = set()

    for entry in entries:
        if not isinstance(entry, dict):
            continue

        raw_id = str(entry.get("id") or entry.get("url") or "")
        download_url = str(
            entry.get("webpage_url")
            or entry.get("url")
            or (f"https://www.youtube.com/shorts/{raw_id}" if raw_id else "")
        )
        youtube_id = extract_video_id(download_url) or extract_video_id(raw_id)
        if not youtube_id or youtube_id in seen:
            continue

        if not download_url.startswith("http"):
            download_url = f"https://www.youtube.com/shorts/{youtube_id}"

        title = str(entry.get("title") or youtube_id)
        description = str(entry.get("description") or "")
        videos.append(
            ShortVideo(
                youtube_id=youtube_id,
                download_url=download_url,
                title=title,
                description=description,
                hashtags=parse_hashtags(f"{title} {description}"),
            )
        )
        seen.add(youtube_id)

    return videos


def _progress_hook(label: str) -> Callable[[dict[str, Any]], None]:
    state = {"next_mark": 25}

    def hook(event: dict[str, Any]) -> None:
        if event.get("status") == "downloading":
            total = event.get("total_bytes") or event.get("total_bytes_estimate") or 0
            done = event.get("downloaded_bytes") or 0
            if not total:
                return
            pct = int(done * 100 / total)
            if pct >= state["next_mark"]:
                state["next_mark"] = pct - (pct % 25) + 25
                logger.info("[%s] download %d%% (%.1f MB)", label, pct, done / 1_048_576)
        elif event.get("status") == "finished":
            logger.info("[%s] download concluído, convertendo para mp4", label)

    return hook


def fetch_video_metadata(url: str) -> VideoMetadata:
    youtube_id = extract_video_id(url)
    if youtube_id is None:
        raise ValueError(f"not a youtube video url: {url}")

    options = {
        "quiet": True,
        "no_warnings": True,
        "noplaylist": True,
        "http_headers": _PT_BR_HEADERS,
        "extractor_args": _YOUTUBE_PORTUGUESE_EXTRACTOR_ARGS,
    }

    try:
        with yt_dlp.YoutubeDL(options) as ydl:
            info = ydl.extract_info(f"https://www.youtube.com/watch?v={youtube_id}", download=False)
    except yt_dlp.utils.DownloadError as exc:
        raise VideoUnavailableError(str(exc)) from exc

    if not isinstance(info, dict):
        raise VideoUnavailableError(f"no metadata returned for {youtube_id}")

    return VideoMetadata(
        youtube_id=youtube_id,
        title=str(info.get("title") or youtube_id),
        duration_seconds=int(info["duration"]) if info.get("duration") else None,
        width=int(info["width"]) if info.get("width") else None,
        height=int(info["height"]) if info.get("height") else None,
        channel=str(info["channel"]) if info.get("channel") else None,
        thumbnail_url=_best_thumbnail(info),
        is_live=bool(info.get("is_live")),
    )


def _best_thumbnail(info: dict[str, Any]) -> str | None:
    thumbnail = info.get("thumbnail")
    if isinstance(thumbnail, str) and thumbnail:
        return thumbnail

    thumbnails = info.get("thumbnails")
    if isinstance(thumbnails, list) and thumbnails:
        last = thumbnails[-1]
        url = last.get("url") if isinstance(last, dict) else None
        if isinstance(url, str) and url:
            return url

    return None


# Cadeia de fallback do vídeo longo: cada tentativa relaxa a exigência anterior.
# 1. melhor par vídeo+áudio até 1080p (preferindo H.264 — remux vira cópia de
#    stream); sem 1080p pega a maior abaixo; acima de 1080 só se for a única.
# 2. só formatos progressivos (arquivo único): elimina merge DASH, a causa mais
#    comum de falha da tentativa 1.
# 3. qualquer formato que o extractor der — último recurso antes do failed.
_VIDEO_FORMAT_ATTEMPTS: list[dict[str, Any]] = [
    {
        "format": "bv*[height<=1080]+ba/b[height<=1080]/bv*+ba/b",
        "format_sort": ["res:1080", "vcodec:h264", "vbr", "fps", "abr"],
    },
    {"format": "b[height<=1080]/b", "format_sort": ["res:1080"]},
    {"format": "b"},
]


def download_video(url: str, output_dir: Path, label: str) -> DownloadedVideo:
    output_dir.mkdir(parents=True, exist_ok=True)

    last_error: Exception | None = None
    for attempt in _VIDEO_FORMAT_ATTEMPTS:
        options: dict[str, Any] = {
            "outtmpl": str(output_dir / "video.%(ext)s"),
            "merge_output_format": "mp4",
            "quiet": True,
            "no_warnings": True,
            "noplaylist": True,
            "progress_hooks": [_progress_hook(label)],
            # Remux (troca de container), não transcode: vídeo longo transcodando
            # levaria horas e o packager HLS re-encoda de qualquer jeito.
            "postprocessors": [{"key": "FFmpegVideoRemuxer", "preferedformat": "mp4"}],
            "http_headers": _PT_BR_HEADERS,
            "extractor_args": _YOUTUBE_PORTUGUESE_EXTRACTOR_ARGS,
            **attempt,
        }

        try:
            with yt_dlp.YoutubeDL(options) as ydl:
                info = ydl.extract_info(url, download=True)
        except Exception as exc:
            last_error = exc
            logger.warning("[%s] formato %r falhou: %s", label, attempt["format"], exc)
            continue

        downloaded = _find_downloaded_file(output_dir)
        if downloaded is None:
            last_error = FileNotFoundError(f"downloaded file not found for {url}")
            continue

        info = info if isinstance(info, dict) else {}

        return DownloadedVideo(
            path=downloaded,
            title=str(info.get("title") or ""),
            duration_seconds=int(info["duration"]) if info.get("duration") else None,
            width=int(info["width"]) if info.get("width") else None,
            height=int(info["height"]) if info.get("height") else None,
        )

    raise last_error if last_error is not None else RuntimeError(f"download failed for {url}")


def _find_downloaded_file(output_dir: Path) -> Path | None:
    for candidate in sorted(output_dir.glob("video.*")):
        if candidate.is_file() and candidate.stat().st_size > 0:
            return candidate
    return None


def download_short(download_url: str, output_dir: Path, label: str | None = None) -> Path:
    output_dir.mkdir(parents=True, exist_ok=True)
    options = {
        # Sem trava de codec: yt-dlp pega o melhor (inclui VP9/AV1, que no YouTube
        # têm bitrate maior que H.264). O postprocessor abaixo transcoda pra
        # H.264 alto bitrate via GPU (videotoolbox/nvenc) ou libx264 crf 18.
        "format": "bestvideo+bestaudio/best",
        "format_sort": ["res", "vbr", "fps", "abr"],
        "merge_output_format": "mp4",
        "outtmpl": str(output_dir / "source.%(ext)s"),
        "quiet": True,
        "no_warnings": True,
        "noplaylist": True,
        "progress_hooks": [_progress_hook(label or download_url)],
        "postprocessors": [{"key": "FFmpegVideoConvertor", "preferedformat": "mp4"}],
        "postprocessor_args": {"FFmpegVideoConvertor": _video_encoder_args()},
        "http_headers": _PT_BR_HEADERS,
        "extractor_args": _YOUTUBE_PORTUGUESE_EXTRACTOR_ARGS,
    }

    with yt_dlp.YoutubeDL(options) as ydl:
        ydl.extract_info(download_url, download=True)

    for candidate in sorted(output_dir.glob("source.*")):
        if candidate.is_file() and candidate.stat().st_size > 0:
            return candidate

    raise FileNotFoundError(f"downloaded file not found for {download_url}")
