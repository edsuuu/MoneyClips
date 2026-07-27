from __future__ import annotations

import logging
from collections.abc import AsyncGenerator
from contextlib import asynccontextmanager

from fastapi import FastAPI, HTTPException, status
from pydantic import BaseModel, HttpUrl

from app.config.settings import settings
from app.jobs.video_worker import VideoDownloadJob, video_worker
from app.jobs.worker import ChannelAlreadyDownloadingError, start_download
from app.logging_config import configure_logging
from app.observability import start_observability
from app.youtube.client import VideoUnavailableError, extract_video_id, fetch_video_metadata

logger = logging.getLogger("shorts.api")


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
    start_observability("download-youtube", "shorts")
    video_worker.start()
    logger.info("starting download-youtube on %s:%s", settings.api_host, settings.api_port)
    yield


app = FastAPI(
    title="download-youtube",
    version="0.3.0",
    description=(
        "Baixa do YouTube direto pro MinIO: Shorts de um canal em lote "
        "(um webhook por item) ou um vídeo longo por URL (fila + webhook). "
        "Sem banco — estado vive no processo."
    ),
    lifespan=lifespan,
)


@app.get("/health")
def health() -> dict[str, str]:
    return {"status": "ok"}


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


def run() -> None:
    import uvicorn

    configure_logging(settings.log_level)
    uvicorn.run("app.main:app", host=settings.api_host, port=settings.api_port)


if __name__ == "__main__":
    run()
