"""Fila assíncrona de transcrição (uma por vez) + webhook de desfecho.

O endpoint síncrono `/transcribe` (usado pelo serviço Video no fluxo de
template) e o assíncrono `/transcriptions` (usado direto pelo Laravel)
compartilham o mesmo `gpu_lock`: a GPU não é reentrante, então roda uma
transcrição por vez, venha de onde vier.
"""

from __future__ import annotations

import logging
import queue
import shutil
import threading
from dataclasses import dataclass
from pathlib import Path
from typing import Any

import httpx

from app.config.settings import settings
from app.pipeline.transcribe import transcribe

logger = logging.getLogger("transcriber.worker")

gpu_lock = threading.Lock()

_WEBHOOK_TIMEOUT_SECONDS = 30.0


@dataclass
class TranscriptionJob:
    job_id: str
    uuid: str
    work_dir: Path
    audio_path: Path
    webhook_url: str


class TranscriptionWorker:
    def __init__(self) -> None:
        self._queue: queue.Queue[TranscriptionJob] = queue.Queue()
        self._started = False

    def start(self) -> None:
        if self._started:
            return
        self._started = True
        threading.Thread(target=self._loop, daemon=True, name="transcription-worker").start()

    def submit(self, job: TranscriptionJob) -> None:
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

    def _process(self, job: TranscriptionJob) -> None:
        try:
            with gpu_lock:
                transcript = transcribe(job.audio_path, job.work_dir / "transcript.json")
        except Exception as exception:
            logger.exception("transcrição falhou (uuid=%s)", job.uuid)
            self._notify(job, {"uuid": job.uuid, "status": "failed", "error": str(exception)})
            return

        self._notify(job, {"uuid": job.uuid, "status": "done", "transcript": transcript})

    def _notify(self, job: TranscriptionJob, payload: dict[str, Any]) -> None:
        try:
            response = httpx.post(
                job.webhook_url,
                json=payload,
                headers={"X-Observability-Token": settings.observability_token},
                timeout=_WEBHOOK_TIMEOUT_SECONDS,
            )
            response.raise_for_status()
            logger.info("webhook enviado (uuid=%s, status=%s)", job.uuid, payload["status"])
        except Exception:
            logger.exception("webhook falhou (uuid=%s)", job.uuid)


worker = TranscriptionWorker()
