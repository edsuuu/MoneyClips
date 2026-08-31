from __future__ import annotations

import logging
import platform
import shutil
import tempfile
from collections.abc import AsyncGenerator
from contextlib import asynccontextmanager
from pathlib import Path
from typing import Annotated
from uuid import uuid4

from fastapi import FastAPI, Form, HTTPException, UploadFile, status
from pydantic import BaseModel, HttpUrl

from app.config.settings import settings
from app.facetracking.worker import FaceTrackingJob, face_tracking_worker
from app.jobs.video_worker import VideoDownloadJob, video_worker
from app.jobs.worker import ChannelAlreadyDownloadingError, start_download
from app.logging_config import configure_logging
from app.observability import start_observability
from app.transcription.device import resolve_device
from app.transcription.worker import TranscriptionJob, transcription_worker
from app.youtube.client import VideoUnavailableError, extract_video_id, fetch_video_metadata

logger = logging.getLogger("media.api")


class DownloadRequest(BaseModel):
    channel_url: HttpUrl
    webhook_url: HttpUrl


class AcceptedResponse(BaseModel):
    status: str = "started"
    count: int
    channel_url: str


class VideoMetadataResponse(BaseModel):
    youtube_id: str
    title: str
    duration_seconds: int | None
    width: int | None
    height: int | None
    channel: str | None
    thumbnail: str | None


class VideoDownloadRequest(BaseModel):
    url: HttpUrl
    video_uuid: str
    video_key: str
    webhook_url: HttpUrl


class VideoDownloadAcceptedResponse(BaseModel):
    video_uuid: str
    status: str = "queued"


@asynccontextmanager
async def lifespan(_app: FastAPI) -> AsyncGenerator[None, None]:
    configure_logging(settings.log_level)
    start_observability(
        settings.service_name or "media",
        "media",
        url=settings.observability_url,
        token=settings.observability_token,
    )
    video_worker.start()
    transcription_worker.start()
    face_tracking_worker.start()
    logger.info("starting media on %s:%s", settings.api_host, settings.api_port)
    yield


app = FastAPI(
    title="media",
    version="0.4.0",
    description=(
        "Serviços Python de mídia: baixa do YouTube direto pro MinIO — Shorts "
        "de um canal em lote (um webhook por item) ou um vídeo longo por URL "
    ),
    lifespan=lifespan,
)


@app.get("/health")
def health() -> dict[str, str]:
    device, _ = resolve_device(
        platform.system(), settings.whisper_device, settings.whisper_compute_type
    )

    return {"status": "ok", "whisper_model": settings.whisper_model, "whisper_device": device}


@app.post(
    "/shorts/download",
    response_model=AcceptedResponse,
    status_code=status.HTTP_202_ACCEPTED,
)
def create_download(payload: DownloadRequest) -> AcceptedResponse:
    channel_url = str(payload.channel_url)
    webhook_url = str(payload.webhook_url)

    try:
        count = start_download(channel_url, webhook_url)
    except ChannelAlreadyDownloadingError:
        raise HTTPException(
            status_code=409,
            detail="channel already downloading",
        ) from None
    except Exception as exc:
        logger.exception("failed to start download for %s", channel_url)
        raise HTTPException(
            status_code=502,
            detail=f"failed to start download: {exc}",
        ) from exc

    return AcceptedResponse(count=count, channel_url=channel_url)


@app.get("/videos/metadata", response_model=VideoMetadataResponse)
def video_metadata(url: str) -> VideoMetadataResponse:
    try:
        metadata = fetch_video_metadata(url)
    except ValueError:
        raise HTTPException(status_code=400, detail="not a youtube video url") from None
    except VideoUnavailableError as exc:
        raise HTTPException(status_code=404, detail=f"video unavailable: {exc}") from exc
    except Exception as exc:
        logger.exception("failed to fetch metadata for %s", url)
        raise HTTPException(status_code=502, detail=f"failed to fetch metadata: {exc}") from exc

    if metadata.is_live:
        raise HTTPException(status_code=400, detail="live streams cannot be downloaded")

    return VideoMetadataResponse(
        youtube_id=metadata.youtube_id,
        title=metadata.title,
        duration_seconds=metadata.duration_seconds,
        width=metadata.width,
        height=metadata.height,
        channel=metadata.channel,
        thumbnail=metadata.thumbnail_url,
    )


@app.post(
    "/videos/download",
    response_model=VideoDownloadAcceptedResponse,
    status_code=status.HTTP_202_ACCEPTED,
)
def create_video_download(payload: VideoDownloadRequest) -> VideoDownloadAcceptedResponse:
    url = str(payload.url)
    if extract_video_id(url) is None:
        raise HTTPException(status_code=400, detail="not a youtube video url")

    video_worker.submit(
        VideoDownloadJob(
            video_uuid=payload.video_uuid,
            video_key=payload.video_key,
            url=url,
            webhook_url=str(payload.webhook_url),
        )
    )

    return VideoDownloadAcceptedResponse(video_uuid=payload.video_uuid)


@app.post("/transcriptions", status_code=status.HTTP_202_ACCEPTED)
def create_transcription(
    audio: UploadFile,
    uuid: Annotated[str, Form()],
    webhook_url: Annotated[str, Form()],
) -> dict[str, str]:
    job_id = uuid4().hex
    work_dir = Path(tempfile.mkdtemp(prefix="transcribe-"))
    source = work_dir / "audio.wav"
    with source.open("wb") as out:
        shutil.copyfileobj(audio.file, out)

    transcription_worker.submit(
        TranscriptionJob(
            job_id=job_id,
            uuid=uuid,
            work_dir=work_dir,
            audio_path=source,
            webhook_url=webhook_url,
        )
    )

    return {"job_id": job_id, "status": "queued"}


@app.post("/face-tracking", status_code=status.HTTP_202_ACCEPTED)
def create_face_tracking(
    video: UploadFile,
    uuid: Annotated[str, Form()],
    webhook_url: Annotated[str, Form()],
    max_keyframes: Annotated[str, Form()] = "40",
) -> dict[str, str]:
    job_id = uuid4().hex
    work_dir = Path(tempfile.mkdtemp(prefix="facetracking-"))
    source = work_dir / "video.mp4"
    with source.open("wb") as out:
        shutil.copyfileobj(video.file, out)

    face_tracking_worker.submit(
        FaceTrackingJob(
            job_id=job_id,
            uuid=uuid,
            work_dir=work_dir,
            video_path=source,
            webhook_url=webhook_url,
            max_keyframes=_parse_max_keyframes(max_keyframes),
        )
    )

    return {"job_id": job_id, "status": "queued"}


def _parse_max_keyframes(raw: str) -> int:
    try:
        value = int(raw)
    except ValueError:
        value = settings.face_tracking_max_keyframes
    return max(1, min(value, 200))


def run() -> None:
    import uvicorn

    configure_logging(settings.log_level)
    uvicorn.run("app.main:app", host=settings.api_host, port=settings.api_port)


if __name__ == "__main__":
    run()
