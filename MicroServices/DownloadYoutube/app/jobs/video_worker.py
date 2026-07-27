"""Fila assíncrona de download de vídeo longo (um por vez) + webhook de desfecho.

O Laravel manda o `video_uuid` e a key EXATA de destino no MinIO
(`videos/{uuid}/{uuid}.mp4`) — este worker só escreve onde mandaram e ecoa o
`video_uuid` no webhook, que fecha o ciclo com claim atômico do lado de lá.
"""

from __future__ import annotations

import logging
import queue
import shutil
import threading
from dataclasses import dataclass
from typing import Any

from app.config.settings import settings
from app.jobs.worker import _send_webhook
from app.storage.client import StorageClient
from app.youtube.client import download_video

logger = logging.getLogger("shorts.video_worker")


@dataclass
class VideoDownloadJob:
    video_uuid: str
    video_key: str
    url: str
    webhook_url: str


class VideoDownloadWorker:
    # ponytail: fila de 1 consumidor (como o Transcriber) — um vídeo longo
    # enfileira os próximos; se virar gargalo, trocar por ThreadPoolExecutor.
    def __init__(self) -> None:
        self._queue: queue.Queue[VideoDownloadJob] = queue.Queue()
        self._started = False

    def start(self) -> None:
        if self._started:
            return
        self._started = True
        threading.Thread(target=self._loop, daemon=True, name="video-download-worker").start()

    def submit(self, job: VideoDownloadJob) -> None:
        self._queue.put(job)
        logger.info("video %s enfileirado (%s)", job.video_uuid, job.url)

    def _loop(self) -> None:
        while True:
            job = self._queue.get()
            try:
                self._process(job)
            finally:
                self._queue.task_done()

    def _process(self, job: VideoDownloadJob) -> None:
        label = f"video:{job.video_uuid}"
        work_dir = settings.temp_dir / f"video-{job.video_uuid}"
        logger.info("%s: iniciando download de %s", label, job.url)

        try:
            downloaded = download_video(job.url, work_dir, label)
            stat = StorageClient().upload_file(downloaded.path, job.video_key)
            size_bytes = int(stat.get("size_bytes") or 0)
            if size_bytes <= 0:
                raise RuntimeError("storage object empty after upload")  # noqa: TRY301

            payload: dict[str, Any] = {
                "video_uuid": job.video_uuid,
                "status": "completed",
                "size_bytes": size_bytes,
                "title": downloaded.title,
                "duration_seconds": downloaded.duration_seconds,
                "width": downloaded.width,
                "height": downloaded.height,
            }
            logger.info("%s: concluído (%.1f MB)", label, size_bytes / 1_048_576)
        except Exception as exc:
            logger.exception("%s: download falhou", label)
            payload = {"video_uuid": job.video_uuid, "status": "failed", "error": str(exc)}
        finally:
            shutil.rmtree(work_dir, ignore_errors=True)

        _send_webhook(
            job.webhook_url,
            payload,
            headers={"X-Observability-Token": settings.observability_token},
        )


video_worker = VideoDownloadWorker()
