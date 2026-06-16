from __future__ import annotations

import logging
from collections.abc import AsyncGenerator
from contextlib import asynccontextmanager
from uuid import uuid4

from fastapi import FastAPI, HTTPException, Query, status
from sqlalchemy import func, select
from sqlalchemy.exc import IntegrityError
from sqlalchemy.orm import Session

from app.config.settings import settings
from app.database.models import ShortDownloadItem, ShortDownloadJob, active_channel_key
from app.database.session import SessionLocal, init_db
from app.jobs.dispatcher import dispatch_completed_items
from app.jobs.worker import resume_unfinished_jobs, start_job
from app.logging_config import configure_logging
from app.schemas import (
    AcceptedResponse,
    BulkDispatchResponse,
    DispatchRequest,
    DispatchResponse,
    DownloadRequest,
    ItemsResponse,
    ItemSummary,
    JobStatusResponse,
)

logger = logging.getLogger("shorts.api")


@asynccontextmanager
async def lifespan(app: FastAPI) -> AsyncGenerator[None, None]:
    configure_logging(settings.log_level)
    logger.info("starting download-shorts on %s:%s", settings.api_host, settings.api_port)
    if settings.auto_create_tables:
        init_db()
    resume_unfinished_jobs()
    yield


app = FastAPI(
    title="download-shorts",
    version="0.1.0",
    description="Simple YouTube Shorts downloader with persistent jobs and storage upload.",
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
    channel_key = active_channel_key(channel_url)

    # The unique index on active_channel_key lets the database (not just this
    # check) guarantee at most one active job per channel, which closes the
    # double-click race that previously created duplicate jobs.
    for _ in range(3):
        job_uuid = str(uuid4())
        with SessionLocal() as session:
            session.add(
                ShortDownloadJob(
                    uuid=job_uuid,
                    channel_url=channel_url,
                    webhook_url=str(payload.webhook_url) if payload.webhook_url else None,
                    dispatch_on_complete=payload.dispatch_on_complete,
                    status="queued",
                    dispatch_status="pending",
                    active_channel_key=channel_key,
                )
            )
            try:
                session.commit()
            except IntegrityError:
                session.rollback()
                existing = (
                    session.execute(
                        select(ShortDownloadJob)
                        .where(ShortDownloadJob.active_channel_key == channel_key)
                        .order_by(ShortDownloadJob.id.desc())
                    )
                    .scalars()
                    .first()
                )
                if existing is not None:
                    # A job for this channel is already running: reuse it.
                    logger.info(
                        "channel already has active job %s (status=%s); reusing instead of "
                        "creating a duplicate",
                        existing.uuid,
                        existing.status,
                    )
                    return AcceptedResponse(job_id=existing.uuid, status=existing.status)
                # The key was freed concurrently (job just finished): retry.
                continue

        start_job(job_uuid)
        return AcceptedResponse(job_id=job_uuid)

    raise HTTPException(status_code=503, detail="could not create job, please retry")


@app.get("/shorts/download/{job_id}", response_model=JobStatusResponse)
def get_download(job_id: str) -> JobStatusResponse:
    with SessionLocal() as session:
        job = session.execute(
            select(ShortDownloadJob).where(ShortDownloadJob.uuid == job_id)
        ).scalar_one_or_none()
        if job is None:
            raise HTTPException(status_code=404, detail="job not found")

        pending = _count_items(session, job.id, "pending")
        processing = _count_items(session, job.id, ["downloading", "uploading", "verifying"])
        completed = _count_items(session, job.id, "completed")
        failed = _count_items(session, job.id, "failed")
        dispatch_pending = _count_dispatch_pending(session, job.id)

        return JobStatusResponse(
            job_id=job.uuid,
            status=job.status,
            dispatch_status=job.dispatch_status,
            total=job.total_items,
            pending=pending,
            processing=processing,
            completed=completed,
            failed=failed,
            dispatch_pending=dispatch_pending,
            last_error=job.last_error,
        )


@app.get("/shorts/items", response_model=ItemsResponse)
def list_items(
    item_status: str = Query("completed", alias="status"),
    limit: int = Query(100, ge=1, le=500),
    offset: int = Query(0, ge=0),
) -> ItemsResponse:
    """Lista itens baixados, deduplicados por youtube_id (fica o mais recente).

    Consumido por orquestradores externos (ex.: Laravel) para sortear vídeos
    do estoque sem que os dados saiam deste banco.
    """
    with SessionLocal() as session:
        latest_ids = (
            select(func.max(ShortDownloadItem.id))
            .where(ShortDownloadItem.status == item_status)
            .group_by(ShortDownloadItem.youtube_id)
            .scalar_subquery()
        )
        base = select(ShortDownloadItem).where(ShortDownloadItem.id.in_(latest_ids))

        total = int(session.execute(select(func.count()).select_from(base.subquery())).scalar_one())
        items = list(
            session.execute(
                base.order_by(ShortDownloadItem.id.desc()).limit(limit).offset(offset)
            ).scalars()
        )

        return ItemsResponse(
            total=total,
            items=[
                ItemSummary(
                    youtube_id=item.youtube_id,
                    title=item.title,
                    hashtags=item.hashtags_list(),
                    storage_path=item.storage_path,
                    storage_size_bytes=item.storage_size_bytes,
                    status=item.status,
                    dispatch_status=item.dispatch_status,
                )
                for item in items
            ],
        )


@app.post("/shorts/download/{job_id}/dispatch", response_model=DispatchResponse)
def dispatch_download(job_id: str, payload: DispatchRequest) -> DispatchResponse:
    try:
        result = dispatch_completed_items(
            job_id,
            mode=payload.mode,
            batch_size=payload.batch_size,
            webhook_url=str(payload.webhook_url) if payload.webhook_url else None,
            delete_after_dispatch=payload.delete_after_dispatch,
        )
    except LookupError:
        raise HTTPException(status_code=404, detail="job not found") from None
    except ValueError as exc:
        raise HTTPException(status_code=400, detail=str(exc)) from None
    except Exception as exc:
        raise HTTPException(status_code=502, detail=f"webhook dispatch failed: {exc}") from exc

    return DispatchResponse(**result)


@app.post("/shorts/dispatch", response_model=BulkDispatchResponse)
def dispatch_pending_downloads(payload: DispatchRequest) -> BulkDispatchResponse:
    with SessionLocal() as session:
        job_ids = list(
            session.execute(
                select(ShortDownloadJob.uuid)
                .join(ShortDownloadItem)
                .where(
                    ShortDownloadItem.status == "completed",
                    ShortDownloadItem.dispatch_status == "pending",
                )
                .group_by(ShortDownloadJob.id, ShortDownloadJob.uuid)
                .order_by(ShortDownloadJob.id)
            ).scalars()
        )

    results: list[DispatchResponse] = []
    remaining_batch = payload.batch_size

    for job_id in job_ids:
        if payload.mode == "batch" and remaining_batch <= 0:
            break

        try:
            result = dispatch_completed_items(
                job_id,
                mode="batch" if payload.mode == "batch" else "all",
                batch_size=remaining_batch if payload.mode == "batch" else payload.batch_size,
                webhook_url=str(payload.webhook_url) if payload.webhook_url else None,
                delete_after_dispatch=payload.delete_after_dispatch,
            )
        except ValueError as exc:
            raise HTTPException(status_code=400, detail=str(exc)) from None
        except Exception as exc:
            raise HTTPException(status_code=502, detail=f"webhook dispatch failed: {exc}") from exc

        response = DispatchResponse(**result)
        results.append(response)

        if payload.mode == "batch":
            remaining_batch -= response.sent_items

    sent_items = sum(result.sent_items for result in results)
    with SessionLocal() as session:
        pending_items = int(
            session.execute(
                select(func.count())
                .select_from(ShortDownloadItem)
                .where(
                    ShortDownloadItem.status == "completed",
                    ShortDownloadItem.dispatch_status == "pending",
                )
            ).scalar_one()
        )

    dispatch_status = "partial" if pending_items > 0 else "dispatched"
    if sent_items == 0 and pending_items == 0:
        dispatch_status = "pending"

    return BulkDispatchResponse(
        sent_items=sent_items,
        dispatch_status=dispatch_status,
        jobs=results,
    )


def _count_items(session: Session, job_id: int, status_filter: str | list[str]) -> int:
    query = (
        select(func.count())
        .select_from(ShortDownloadItem)
        .where(ShortDownloadItem.job_id == job_id)
    )
    if isinstance(status_filter, list):
        query = query.where(ShortDownloadItem.status.in_(status_filter))
    else:
        query = query.where(ShortDownloadItem.status == status_filter)
    return int(session.execute(query).scalar_one())


def _count_dispatch_pending(session: Session, job_id: int) -> int:
    return int(
        session.execute(
            select(func.count())
            .select_from(ShortDownloadItem)
            .where(
                ShortDownloadItem.job_id == job_id,
                ShortDownloadItem.status == "completed",
                ShortDownloadItem.dispatch_status == "pending",
            )
        ).scalar_one()
    )


def run() -> None:
    import uvicorn

    configure_logging(settings.log_level)
    uvicorn.run("app.main:app", host=settings.api_host, port=settings.api_port)


if __name__ == "__main__":
    run()
