"""Fila assíncrona de face tracking (um por vez) + webhook de desfecho.

Fila própria, como a da transcrição. O `gpu_lock` é o MESMO da transcrição
(importado de lá): MediaPipe e faster-whisper disputam a mesma GPU, e dois
locks independentes não protegeriam nada.
"""

from __future__ import annotations

import logging
import queue
import shutil
import threading
from dataclasses import dataclass
from pathlib import Path
from typing import Any

from app.config.settings import settings
from app.facetracking.tracker import track
from app.jobs.worker import _send_webhook
from app.transcription.worker import gpu_lock

logger = logging.getLogger("media.facetracking.worker")


@dataclass
class FaceTrackingJob:
    job_id: str
    uuid: str
    work_dir: Path
    video_path: Path
    webhook_url: str
    max_keyframes: int


class FaceTrackingWorker:
    def __init__(self) -> None:
        self._queue: queue.Queue[FaceTrackingJob] = queue.Queue()
        self._started = False

    def start(self) -> None:
        if self._started:
            return
        self._started = True
        threading.Thread(target=self._loop, daemon=True, name="facetracking-worker").start()

    def submit(self, job: FaceTrackingJob) -> None:
        self._queue.put(job)
        logger.info("job %s enfileirado (uuid=%s)", job.job_id, job.uuid)

    def _loop(self) -> None:
        while True:
            job = self._queue.get()
            try:
                self._process(job)
            finally:
                shutil.rmtree(job.work_dir, ignore_errors=True)
                self._queue.task_done()

    def _process(self, job: FaceTrackingJob) -> None:
        try:
            with gpu_lock:
                result = track(
                    job.video_path,
                    max_keyframes=job.max_keyframes,
                    sample_fps=settings.face_tracking_sample_fps,
                    models_dir=settings.face_tracking_models_dir,
                    delegate=settings.face_tracking_delegate,
                )
        except Exception as exception:
            logger.exception("face tracking falhou (uuid=%s)", job.uuid)
            self._notify(
                job,
                {
                    "uuid": job.uuid,
                    "status": "failed",
                    "error": str(exception),
                    "keyframes": [],
                    "speakers": [],
                    "source": None,
                },
            )
            return

        logger.info(
            "face tracking concluído (uuid=%s): %d keyframes, %d falas",
            job.uuid,
            len(result.keyframes),
            len(result.speakers),
        )
        self._notify(
            job,
            {
                "uuid": job.uuid,
                "status": "done",
                "keyframes": result.keyframes,
                "speakers": result.speakers,
                "source": result.source,
                "error": None,
            },
        )

    def _notify(self, job: FaceTrackingJob, payload: dict[str, Any]) -> None:
        _send_webhook(
            job.webhook_url,
            payload,
            headers={"X-Observability-Token": settings.observability_token},
        )


face_tracking_worker = FaceTrackingWorker()
