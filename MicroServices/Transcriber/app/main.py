"""Serviço de transcrição (faster-whisper).

Faz UMA coisa: recebe um áudio e devolve o transcript com timestamps por
palavra. É assíncrono — `POST /transcriptions` responde 202 na hora, enfileira
(uma transcrição por vez, GPU/CPU não é reentrante) e devolve o resultado por
webhook. O device (CUDA em produção, CPU no macOS de dev) é resolvido por S.O.

Todo o resto do antigo AutoCaption — legenda .ass, moldura do template, render
das variantes, storage, status e webhook — vive no microserviço `Video`
(Node/ffmpeg, porta 8790), que é quem chama este endpoint.
"""

from __future__ import annotations

import logging
import platform
import tempfile
from collections.abc import AsyncGenerator
from contextlib import asynccontextmanager
from pathlib import Path
from typing import Annotated
from uuid import uuid4

from fastapi import FastAPI, Form, UploadFile

from app.config.settings import settings
from app.logging_config import configure_logging
from app.observability import start_observability
from app.pipeline.device import resolve_device
from app.pipeline.worker import TranscriptionJob, worker

logger = logging.getLogger("transcriber.api")

_CHUNK = 1024 * 1024


@asynccontextmanager
async def lifespan(_app: FastAPI) -> AsyncGenerator[None, None]:
    configure_logging(settings.log_level)
    start_observability("transcriber", "transcriber")
    worker.start()
    logger.info("starting transcriber on %s:%s", settings.api_host, settings.api_port)
    yield


app = FastAPI(
    title="transcriber",
    version="0.2.0",
    description="Transcreve áudio com faster-whisper e devolve os timestamps por palavra.",
    lifespan=lifespan,
)


@app.get("/health")
def health() -> dict[str, str]:
    device, _ = resolve_device(
        platform.system(), settings.whisper_device, settings.whisper_compute_type
    )

    return {
        "status": "ok",
        "model": settings.whisper_model,
        "device": device,
        "language": settings.whisper_language,
    }


@app.post("/transcriptions", status_code=202)
async def create_async_transcription(
    audio: UploadFile,
    uuid: Annotated[str, Form()],
    webhook_url: Annotated[str, Form()],
) -> dict:
    job_id = uuid4().hex
    work_dir = Path(tempfile.mkdtemp(prefix="transcribe-async-"))
    source = work_dir / "audio.wav"
    with source.open("wb") as out:
        while chunk := await audio.read(_CHUNK):
            out.write(chunk)

    worker.submit(
        TranscriptionJob(
            job_id=job_id,
            uuid=uuid,
            work_dir=work_dir,
            audio_path=source,
            webhook_url=webhook_url,
        )
    )

    return {"job_id": job_id, "status": "queued"}


def run() -> None:
    import uvicorn

    configure_logging(settings.log_level)
    uvicorn.run("app.main:app", host=settings.api_host, port=settings.api_port)


if __name__ == "__main__":
    run()
