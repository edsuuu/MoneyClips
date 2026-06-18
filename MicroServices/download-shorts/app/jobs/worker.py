from __future__ import annotations

import logging
import shutil
import threading
import time
from concurrent.futures import ThreadPoolExecutor
from typing import Any

import httpx

from app.config.settings import settings
from app.storage.client import StorageClient, storage_path_for
from app.youtube.client import ShortVideo, download_short, list_shorts

logger = logging.getLogger("shorts.worker")


_active_channels: set[str] = set()
_active_lock = threading.Lock()


class ChannelAlreadyDownloading(Exception):
    pass


def start_download(channel_url: str, webhook_url: str) -> int:
    normalized = channel_url.strip().rstrip("/")
    with _active_lock:
        if normalized in _active_channels:
            raise ChannelAlreadyDownloading(normalized)
        _active_channels.add(normalized)

    try:
        videos = list_shorts(channel_url)
    except Exception:
        _release_channel(normalized)
        raise

    if not videos:
        _release_channel(normalized)
        logger.info("channel %s has no shorts; nothing to do", channel_url)
        return 0

    logger.info(
        "channel %s: listed %d shorts; launching pool (%d workers)",
        channel_url,
        len(videos),
        settings.download_workers,
    )

    thread = threading.Thread(
        target=_run_pool,
        args=(normalized, channel_url, webhook_url, videos),
        daemon=True,
    )
    thread.start()

    return len(videos)


def _run_pool(
    channel_key: str,
    channel_url: str,
    webhook_url: str,
    videos: list[ShortVideo],
) -> None:
    storage = StorageClient()
    settings.temp_dir.mkdir(parents=True, exist_ok=True)

    try:
        with ThreadPoolExecutor(max_workers=settings.download_workers) as pool:
            for video in videos:
                pool.submit(
                    _process_one,
                    video,
                    channel_url,
                    webhook_url,
                    storage,
                )
    finally:
        _release_channel(channel_key)


def _release_channel(channel_key: str) -> None:
    with _active_lock:
        _active_channels.discard(channel_key)


def _process_one(
    video: ShortVideo,
    channel_url: str,
    webhook_url: str,
    storage: StorageClient,
) -> None:
    object_path = storage_path_for(video.youtube_id)
    label = f"short:{video.youtube_id}"
    work_dir = settings.temp_dir / video.youtube_id

    try:
        if storage.exists(object_path):
            stat = storage.stat(object_path)
            _send_webhook(
                webhook_url,
                _build_payload(video, channel_url, "completed", stat),
            )
            return

        last_error: str | None = None
        for attempt in range(1, settings.max_attempts + 1):
            try:
                work_dir.mkdir(parents=True, exist_ok=True)
                downloaded = download_short(video.download_url, work_dir, label=label)
                stat = storage.upload_file(downloaded, object_path)
                if int(stat.get("size_bytes") or 0) <= 0:
                    raise RuntimeError("storage object empty after upload")
                _send_webhook(
                    webhook_url,
                    _build_payload(video, channel_url, "completed", stat),
                )
                return
            except Exception as exc:
                last_error = str(exc)
                logger.warning(
                    "%s: attempt %d/%d failed: %s",
                    label,
                    attempt,
                    settings.max_attempts,
                    exc,
                )
                if attempt < settings.max_attempts:
                    time.sleep(1)
            finally:
                shutil.rmtree(work_dir, ignore_errors=True)

        _send_webhook(
            webhook_url,
            _build_payload(video, channel_url, "failed", None, error=last_error),
        )
    except Exception as exc:
        logger.exception("%s: unexpected error: %s", label, exc)
        _send_webhook(
            webhook_url,
            _build_payload(video, channel_url, "failed", None, error=str(exc)),
        )


def _build_payload(
    video: ShortVideo,
    channel_url: str,
    status: str,
    stat: dict[str, Any] | None,
    *,
    error: str | None = None,
) -> dict[str, Any]:
    item: dict[str, Any] = {
        "youtube_id": video.youtube_id,
        "title": video.title,
        "hashtags": video.hashtags,
        "status": status,
    }
    if stat is not None:
        item["storage_path"] = stat.get("path")
        item["storage_size_bytes"] = stat.get("size_bytes")
        item["storage_mime_type"] = stat.get("mime_type")
    if error is not None:
        item["error"] = error

    return {"channel_url": channel_url, "items": [item]}


def _send_webhook(webhook_url: str, payload: dict[str, Any]) -> None:
    delays = settings.webhook_retry_delays_seconds
    timeout = settings.webhook_timeout_seconds

    last_error: Exception | None = None
    for index, delay in enumerate(delays, start=1):
        try:
            with httpx.Client(timeout=timeout) as client:
                response = client.post(webhook_url, json=payload)
                response.raise_for_status()
            return
        except Exception as exc:
            last_error = exc
            logger.warning(
                "webhook attempt %d/%d failed (%s); retrying in %.1fs",
                index,
                len(delays),
                exc,
                delay,
            )
            if index < len(delays):
                time.sleep(delay)

    logger.error(
        "webhook gave up after %d attempts: %s (payload=%s)",
        len(delays),
        last_error,
        payload,
    )
