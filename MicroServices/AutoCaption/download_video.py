# ruff: noqa: T201

from __future__ import annotations

import sys
from pathlib import Path
from typing import Any

from yt_dlp import YoutubeDL

DOWNLOADS_DIR = Path(__file__).parent / "downloads"


def download(url: str) -> Path:
    DOWNLOADS_DIR.mkdir(parents=True, exist_ok=True)
    options: dict[str, Any] = {
        "format": "bestvideo*+bestaudio/best",
        "merge_output_format": "mkv",
        "outtmpl": str(DOWNLOADS_DIR / "%(title)s [%(id)s].%(ext)s"),
        "noplaylist": True,
        "concurrent_fragment_downloads": 4,
    }
    with YoutubeDL(options) as ydl:
        info = ydl.extract_info(url, download=True)
        requested = info.get("requested_downloads") or []
        if requested and requested[0].get("filepath"):
            return Path(requested[0]["filepath"])
        return Path(ydl.prepare_filename(info))


def main() -> None:
    urls = sys.argv[1:]
    if not urls:
        print("uso: python download_video.py <url> [<url> ...]")
        sys.exit(1)
    for url in urls:
        path = download(url)
        print(f"baixado: {path}")


if __name__ == "__main__":
    main()
