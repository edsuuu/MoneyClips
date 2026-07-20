"""Serviço de transcrição (faster-whisper/CUDA).

Faz UMA coisa: recebe um wav e devolve o transcript com timestamps por palavra.
Todo o resto do antigo AutoCaption — legenda .ass, moldura do template, render
das variantes, storage, status e webhook — vive no microserviço `Video`
(Node/ffmpeg, porta 8790), que é quem chama este endpoint.

O modelo fica carregado no processo e uma requisição por vez usa a GPU: o
`_gpu_lock` serializa, como o worker antigo fazia.
"""

from __future__ import annotations

import logging
import tempfile
import threading
from collections.abc import AsyncGenerator
from contextlib import asynccontextmanager
from pathlib import Path

from fastapi import FastAPI, HTTPException, UploadFile

from app.config.settings import settings
from app.logging_config import configure_logging
from app.observability import start_observability
from app.pipeline.transcribe import transcribe

logger = logging.getLogger("autocaption.api")

_CHUNK = 1024 * 1024
_gpu_lock = threading.Lock()


@asynccontextmanager
async def lifespan(_app: FastAPI) -> AsyncGenerator[None, None]:
    configure_logging(settings.log_level)
    start_observability("autocaption", "autocaption")
    logger.info("starting autocaption on %s:%s", settings.api_host, settings.api_port)
    yield


app = FastAPI(
    title="autocaption",
    version="0.2.0",
    description="Transcreve áudio com faster-whisper e devolve os timestamps por palavra.",
    lifespan=lifespan,
)


@app.get("/health")
def health() -> dict[str, str]:
    return {
        "status": "ok",
        "model": settings.whisper_model,
        "device": settings.whisper_device,
        "language": settings.whisper_language,
    }


@app.post("/transcribe")
async def create_transcription(audio: UploadFile) -> dict:
    if not (audio.filename or "").lower().endswith(".wav"):
        raise HTTPException(status_code=422, detail="envie um .wav (mono 16kHz)")

    with tempfile.TemporaryDirectory(prefix="transcribe-") as tmp:
        source = Path(tmp) / "audio.wav"
        with source.open("wb") as out:
            while chunk := await audio.read(_CHUNK):
                out.write(chunk)

        # O modelo é global e a GPU não é reentrante: uma transcrição por vez.
        with _gpu_lock:
            return transcribe(source, Path(tmp) / "transcript.json")


def run() -> None:
    import uvicorn

    configure_logging(settings.log_level)
    uvicorn.run("app.main:app", host=settings.api_host, port=settings.api_port)


if __name__ == "__main__":
    run()
