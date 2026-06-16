from __future__ import annotations

import logging
import re
from collections.abc import Callable
from dataclasses import dataclass
from pathlib import Path
from typing import Any
from urllib.parse import parse_qs, urlparse

import yt_dlp

logger = logging.getLogger("shorts.youtube")


@dataclass(frozen=True)
class ShortVideo:
    youtube_id: str
    download_url: str
    title: str
    description: str
    hashtags: list[str]


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


def list_shorts(channel_url: str) -> list[ShortVideo]:
    shorts_url = normalize_shorts_url(channel_url)
    options = {
        "quiet": True,
        "no_warnings": True,
        "extract_flat": True,
        "ignoreerrors": True,
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
    """Loga o percentual do download em marcos de 25% (vários downloads rodam
    em paralelo, então cada linha é prefixada com o id do vídeo)."""
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


def download_short(download_url: str, output_dir: Path, label: str | None = None) -> Path:
    output_dir.mkdir(parents=True, exist_ok=True)
    options = {
        "format": "bestvideo[ext=mp4]+bestaudio[ext=m4a]/best[ext=mp4]/best",
        "merge_output_format": "mp4",
        "outtmpl": str(output_dir / "source.%(ext)s"),
        "quiet": True,
        "no_warnings": True,
        "noplaylist": True,
        "progress_hooks": [_progress_hook(label or download_url)],
        "postprocessors": [{"key": "FFmpegVideoConvertor", "preferedformat": "mp4"}],
    }

    with yt_dlp.YoutubeDL(options) as ydl:
        ydl.extract_info(download_url, download=True)

    for candidate in sorted(output_dir.glob("source.*")):
        if candidate.is_file() and candidate.stat().st_size > 0:
            return candidate

    raise FileNotFoundError(f"downloaded file not found for {download_url}")
