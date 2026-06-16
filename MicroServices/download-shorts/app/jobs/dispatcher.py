from __future__ import annotations

import logging

import httpx
from sqlalchemy import func, select
from sqlalchemy.orm import Session

from app.config.settings import settings
from app.database.models import ShortDownloadItem, ShortDownloadJob
from app.database.session import SessionLocal

logger = logging.getLogger("shorts.dispatcher")


def _item_payload(item: ShortDownloadItem) -> dict:
    storage_path = item.storage_path or ""
    return {
        "youtube_id": item.youtube_id,
        "download_url": item.download_url,
        "title": item.title or item.youtube_id,
        "hashtags": item.hashtags_list(),
        "storage_path": storage_path,
        "storage": {
            "path": storage_path,
            "size_bytes": item.storage_size_bytes,
            "mime_type": item.storage_mime_type,
        },
    }


def _count_items(session: Session, job_id: int, status: str) -> int:
    return int(
        session.execute(
            select(func.count())
            .select_from(ShortDownloadItem)
            .where(
                ShortDownloadItem.job_id == job_id,
                ShortDownloadItem.status == status,
            )
        ).scalar_one()
    )


def dispatch_completed_items(
    job_uuid: str,
    mode: str = "batch",
    batch_size: int = 50,
    webhook_url: str | None = None,
    delete_after_dispatch: bool = False,
) -> dict:
    if mode not in {"batch", "all"}:
        raise ValueError("mode must be 'batch' or 'all'")

    logger.info("job %s: dispatch requested (mode=%s, batch_size=%d)", job_uuid, mode, batch_size)
    with SessionLocal() as session:
        job = session.execute(
            select(ShortDownloadJob).where(ShortDownloadJob.uuid == job_uuid)
        ).scalar_one_or_none()
        if job is None:
            raise LookupError("job not found")

        target_webhook = webhook_url or job.webhook_url
        if not target_webhook:
            raise ValueError("job has no webhook_url; provide webhook_url in the dispatch request")

        query = (
            select(ShortDownloadItem)
            .where(
                ShortDownloadItem.job_id == job.id,
                ShortDownloadItem.status == "completed",
                ShortDownloadItem.dispatch_status == "pending",
            )
            .order_by(ShortDownloadItem.id)
        )
        if mode == "batch":
            query = query.limit(batch_size)

        items = list(session.execute(query).scalars())
        if not items:
            logger.info("job %s: no pending items to dispatch", job.uuid)
            remaining = _pending_dispatch_count(session, job.id)
            completed = _count_items(session, job.id, "completed")
            is_final = job.status in {"completed", "completed_partial", "failed"}
            if remaining > 0:
                job.dispatch_status = "partial"
            elif is_final and completed > 0:
                job.dispatch_status = "dispatched"
            else:
                job.dispatch_status = "pending"
            session.commit()
            return {"job_id": job.uuid, "sent_items": 0, "dispatch_status": job.dispatch_status}

        completed = _count_items(session, job.id, "completed")
        failed = _count_items(session, job.id, "failed")
        payload = {
            "event": "shorts.download.dispatched",
            "job_id": job.uuid,
            "channel_url": job.channel_url,
            "summary": {
                "total": job.total_items,
                "completed": completed,
                "failed": failed,
            },
            "items": [_item_payload(item) for item in items],
        }

        logger.info("job %s: posting %d item(s) to webhook", job.uuid, len(items))
        try:
            with httpx.Client(timeout=settings.webhook_timeout_seconds) as client:
                response = client.post(target_webhook, json=payload)
                response.raise_for_status()
        except Exception as exc:
            job.dispatch_status = "failed"
            job.last_error = str(exc)
            session.commit()
            logger.error("job %s: webhook POST failed: %s", job.uuid, exc)
            raise

        if delete_after_dispatch:
            for item in items:
                session.delete(item)
        else:
            for item in items:
                item.dispatch_status = "dispatched"

        session.flush()

        remaining = _pending_dispatch_count(session, job.id)
        job.dispatch_status = "dispatched" if remaining == 0 else "partial"
        job.last_error = None
        session.commit()

        logger.info(
            "job %s: dispatched %d item(s) (dispatch_status=%s, %d still pending)",
            job.uuid,
            len(items),
            job.dispatch_status,
            remaining,
        )

        return {
            "job_id": job.uuid,
            "sent_items": len(items),
            "dispatch_status": job.dispatch_status,
        }


def _pending_dispatch_count(session: Session, job_id: int) -> int:
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
