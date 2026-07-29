"""Fila assíncrona de transcrição (uma por vez) + webhook de desfecho.

Fila própria, separada das filas de download: um download longo nunca segura
uma transcrição (e vice-versa). O `gpu_lock` garante uma transcrição por vez
— a GPU não é reentrante — mesmo que outro ponto de entrada apareça.
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
from app.jobs.worker import _send_webhook
from app.transcription.transcribe import transcribe

logger = logging.getLogger("media.transcription.worker")

gpu_lock = threading.Lock()


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
        _send_webhook(
            job.webhook_url,
            payload,
            headers={"X-Observability-Token": settings.observability_token},
        )


transcription_worker = TranscriptionWorker()
