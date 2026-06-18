from __future__ import annotations

import logging
from collections.abc import AsyncGenerator
from contextlib import asynccontextmanager

from fastapi import FastAPI, HTTPException, status
from pydantic import BaseModel, HttpUrl

from app.config.settings import settings
from app.jobs.worker import ChannelAlreadyDownloading, start_download
from app.logging_config import configure_logging

logger = logging.getLogger("shorts.api")


class DownloadRequest(BaseModel):
    channel_url: HttpUrl
    webhook_url: HttpUrl


class AcceptedResponse(BaseModel):
    status: str = "started"
    count: int
    channel_url: str


@asynccontextmanager
async def lifespan(app: FastAPI) -> AsyncGenerator[None, None]:
    configure_logging(settings.log_level)
    logger.info("starting download-shorts on %s:%s", settings.api_host, settings.api_port)
    yield


app = FastAPI(
    title="download-shorts",
    version="0.2.0",
    description=(
        "Lista os Shorts de um canal, baixa em paralelo e dispara um webhook "
        "por item concluído. Sem banco — estado vive no processo."
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
    except ChannelAlreadyDownloading:
        raise HTTPException(
            status_code=409,
            detail="channel already downloading",
        ) from None
    except Exception as exc:
        logger.exception("failed to start download for %s: %s", channel_url, exc)
        raise HTTPException(
            status_code=502,
            detail=f"failed to start download: {exc}",
        ) from exc

    return AcceptedResponse(count=count, channel_url=channel_url)


def run() -> None:
    import uvicorn

    configure_logging(settings.log_level)
    uvicorn.run("app.main:app", host=settings.api_host, port=settings.api_port)


if __name__ == "__main__":
    run()
