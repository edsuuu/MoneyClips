from __future__ import annotations

import logging
import shutil
import threading
import time
from concurrent.futures import ThreadPoolExecutor, as_completed
from datetime import UTC, datetime
from pathlib import Path

from sqlalchemy import func, select, update
from sqlalchemy.orm import Session

from app.config.settings import settings
from app.database.models import ShortDownloadItem, ShortDownloadJob
from app.database.session import SessionLocal
from app.jobs.dispatcher import dispatch_completed_items
from app.storage.client import StorageClient, storage_path_for
from app.youtube.client import ShortVideo, download_short, list_shorts

logger = logging.getLogger("shorts.worker")

ACTIVE_ITEM_STATUSES = {"downloading", "uploading", "verifying"}
FINAL_ITEM_STATUSES = {"completed", "failed"}

_running_jobs: set[str] = set()
_running_lock = threading.Lock()


def start_job(job_uuid: str) -> None:
    with _running_lock:
        if job_uuid in _running_jobs:
            logger.debug("job %s already running in this process; skip", job_uuid)
            return
        _running_jobs.add(job_uuid)

    logger.info("job %s: launching worker thread", job_uuid)
    thread = threading.Thread(target=_run_guarded, args=(job_uuid,), daemon=True)
    thread.start()


def resume_unfinished_jobs() -> None:
    with SessionLocal() as session:
        jobs = list(
            session.execute(
                select(ShortDownloadJob.uuid).where(
                    ShortDownloadJob.status.in_(["queued", "listing", "processing"])
                )
            ).scalars()
        )

    if jobs:
        logger.info("resuming %d unfinished job(s): %s", len(jobs), ", ".join(jobs))
    for job_uuid in jobs:
        start_job(job_uuid)


def _run_guarded(job_uuid: str) -> None:
    try:
        process_job(job_uuid)
    finally:
        with _running_lock:
            _running_jobs.discard(job_uuid)


def process_job(job_uuid: str) -> None:
    logger.info("job %s: pipeline started", job_uuid)
    try:
        _seed_items(job_uuid)
        _reset_active_items(job_uuid)
        _process_items(job_uuid)
        _finish_job(job_uuid)
    except Exception as exc:
        logger.exception("job %s: pipeline crashed: %s", job_uuid, exc)
        with SessionLocal() as session:
            job = _get_job(session, job_uuid)
            if job is not None:
                job.status = "failed"
                job.last_error = str(exc)
                job.finished_at = _now()
                job.active_channel_key = None
                session.commit()


def _seed_items(job_uuid: str) -> None:
    with SessionLocal() as session:
        job = _get_job(session, job_uuid)
        if job is None:
            return
        existing_items = _count_items(session, job.id)
        if existing_items > 0:
            logger.info("job %s: resuming with %d existing item(s)", job_uuid, existing_items)
            job.status = "processing"
            session.commit()
            return
        job.status = "listing"
        job.started_at = job.started_at or _now()
        session.commit()
        channel_url = job.channel_url

    logger.info("job %s: listing shorts for channel %s", job_uuid, channel_url)
    videos = list_shorts(channel_url)
    logger.info("job %s: channel returned %d short(s)", job_uuid, len(videos))

    with SessionLocal() as session:
        job = _get_job(session, job_uuid)
        if job is None:
            return

        for video in videos:
            _upsert_pending_item(session, job.id, job.channel_url, video)

        job.total_items = _count_items(session, job.id)
        job.status = "processing" if job.total_items > 0 else "completed"
        if job.total_items == 0:
            job.finished_at = _now()
            logger.info("job %s: no shorts found; nothing to download", job_uuid)
        else:
            logger.info("job %s: %d item(s) queued for download", job_uuid, job.total_items)
        session.commit()


def _upsert_pending_item(
    session: Session,
    job_id: int,
    channel_url: str,
    video: ShortVideo,
) -> None:
    item = session.execute(
        select(ShortDownloadItem).where(
            ShortDownloadItem.job_id == job_id,
            ShortDownloadItem.youtube_id == video.youtube_id,
        )
    ).scalar_one_or_none()
    if item is not None:
        item.download_url = video.download_url
        item.title = video.title
        item.description = video.description
        item.hashtags = video.hashtags
        return

    session.add(
        ShortDownloadItem(
            job_id=job_id,
            youtube_id=video.youtube_id,
            download_url=video.download_url,
            title=video.title,
            description=video.description,
            hashtags=video.hashtags,
            status="pending",
            dispatch_status="pending",
        )
    )


def _reset_active_items(job_uuid: str) -> None:
    with SessionLocal() as session:
        job = _get_job(session, job_uuid)
        if job is None:
            return
        result = session.execute(
            update(ShortDownloadItem)
            .where(
                ShortDownloadItem.job_id == job.id,
                ShortDownloadItem.status.in_(list(ACTIVE_ITEM_STATUSES)),
            )
            .values(status="pending")
        )
        session.commit()
        reset_count = result.rowcount  # type: ignore[attr-defined]
        if reset_count:
            logger.info("job %s: reset %d stuck item(s) back to pending", job_uuid, reset_count)


def _process_items(job_uuid: str) -> None:
    with SessionLocal() as session:
        job = _get_job(session, job_uuid)
        if job is None or job.status == "completed":
            return
        item_ids = list(
            session.execute(
                select(ShortDownloadItem.id)
                .where(
                    ShortDownloadItem.job_id == job.id,
                    ShortDownloadItem.status == "pending",
                )
                .order_by(ShortDownloadItem.id)
            ).scalars()
        )

    if not item_ids:
        return

    workers = max(1, settings.download_workers)
    logger.info(
        "job %s: processing %d pending item(s) with %d worker(s)",
        job_uuid,
        len(item_ids),
        workers,
    )
    with ThreadPoolExecutor(max_workers=workers) as executor:
        futures = [executor.submit(_process_item, item_id) for item_id in item_ids]
        for future in as_completed(futures):
            future.result()


def _process_item(item_id: int) -> None:
    for attempt in range(settings.max_attempts):
        tmp_dir: Path | None = None
        try:
            with SessionLocal() as session:
                item = session.get(ShortDownloadItem, item_id)
                if item is None or item.status == "completed":
                    return
                if item.attempts >= settings.max_attempts:
                    logger.warning("item %d: already at max attempts, marking failed", item_id)
                    item.status = "failed"
                    session.commit()
                    _refresh_job_counts(item.job_id)
                    return

                item.status = "downloading"
                item.attempts += 1
                item.last_error = None
                session.commit()

                youtube_id = item.youtube_id
                download_url = item.download_url
                job_id = item.job_id
                logger.info(
                    "item %d (%s): downloading (attempt %d/%d)",
                    item_id,
                    youtube_id,
                    item.attempts,
                    settings.max_attempts,
                )

            storage = StorageClient()
            object_path = storage_path_for(youtube_id)
            if storage.exists(object_path):
                logger.info(
                    "item %d (%s): already in storage, skipping download", item_id, youtube_id
                )
                info = storage.stat(object_path)
                _mark_completed(item_id, object_path, info, downloaded=False)
                return

            tmp_dir = settings.temp_dir / str(item_id) / f"attempt-{attempt + 1}"
            local_file = download_short(download_url, tmp_dir, label=youtube_id)

            with SessionLocal() as session:
                item = session.get(ShortDownloadItem, item_id)
                if item is None:
                    return
                item.status = "uploading"
                item.downloaded_at = _now()
                session.commit()

            logger.info("item %d (%s): uploading to %s", item_id, youtube_id, object_path)
            storage.upload_file(local_file, object_path, content_type="video/mp4")

            with SessionLocal() as session:
                item = session.get(ShortDownloadItem, item_id)
                if item is None:
                    return
                item.status = "verifying"
                item.uploaded_at = _now()
                session.commit()

            info = storage.stat(object_path)
            if int(info.get("size_bytes") or 0) <= 0:
                raise RuntimeError(f"storage object is empty: {object_path}")

            _mark_completed(item_id, object_path, info, downloaded=True)
            return
        except Exception as exc:
            should_retry = attempt + 1 < settings.max_attempts
            with SessionLocal() as session:
                item = session.get(ShortDownloadItem, item_id)
                if item is not None:
                    label = item.youtube_id
                    item.last_error = str(exc)
                    item.status = "pending" if should_retry else "failed"
                    job_id = item.job_id
                    session.commit()
                else:
                    label = "?"
                    job_id = None
            if should_retry:
                logger.warning(
                    "item %d (%s): attempt %d failed (%s); retrying",
                    item_id,
                    label,
                    attempt + 1,
                    exc,
                )
            else:
                logger.error(
                    "item %d (%s): failed permanently after %d attempt(s): %s",
                    item_id,
                    label,
                    settings.max_attempts,
                    exc,
                )
            if job_id is not None:
                _refresh_job_counts(job_id)
            if should_retry:
                time.sleep(1)
                continue
            return
        finally:
            if tmp_dir is not None:
                shutil.rmtree(tmp_dir.parent, ignore_errors=True)


def _mark_completed(
    item_id: int,
    object_path: str,
    storage_info: dict[str, int | str | None],
    *,
    downloaded: bool,
) -> None:
    with SessionLocal() as session:
        item = session.get(ShortDownloadItem, item_id)
        if item is None:
            return
        item.status = "completed"
        item.storage_path = object_path
        item.storage_size_bytes = int(storage_info.get("size_bytes") or 0)
        item.storage_mime_type = str(storage_info.get("mime_type") or "video/mp4")
        item.storage_verified_at = _now()
        if downloaded and item.downloaded_at is None:
            item.downloaded_at = _now()
        if downloaded and item.uploaded_at is None:
            item.uploaded_at = _now()
        item.last_error = None
        job_id = item.job_id
        youtube_id = item.youtube_id
        size_bytes = item.storage_size_bytes
        session.commit()
    logger.info(
        "item %d (%s): completed -> %s (%d bytes)", item_id, youtube_id, object_path, size_bytes
    )
    _refresh_job_counts(job_id)


def _finish_job(job_uuid: str) -> None:
    with SessionLocal() as session:
        job = _get_job(session, job_uuid)
        if job is None:
            return
        _refresh_job_counts_in_session(session, job)
        if job.total_items == 0:
            job.status = "completed"
        elif job.failed_items == 0:
            job.status = "completed"
        elif job.completed_items > 0:
            job.status = "completed_partial"
        else:
            job.status = "failed"
        job.finished_at = _now()
        # Job reached a terminal state: free the channel for future runs.
        job.active_channel_key = None
        dispatch_on_complete = job.dispatch_on_complete
        completed_items = job.completed_items
        final_status = job.status
        total_items = job.total_items
        failed_items = job.failed_items
        session.commit()

    logger.info(
        "job %s: finished status=%s (total=%d completed=%d failed=%d)",
        job_uuid,
        final_status,
        total_items,
        completed_items,
        failed_items,
    )

    if dispatch_on_complete and completed_items > 0:
        logger.info("job %s: auto-dispatching %d completed item(s)", job_uuid, completed_items)
        try:
            dispatch_completed_items(job_uuid, mode="all")
        except Exception as exc:
            # The job stays finished; dispatch_status shows the delivery failure.
            logger.warning("job %s: auto-dispatch failed: %s", job_uuid, exc)
            return


def _refresh_job_counts(job_id: int) -> None:
    with SessionLocal() as session:
        job = session.get(ShortDownloadJob, job_id)
        if job is None:
            return
        _refresh_job_counts_in_session(session, job)
        session.commit()


def _refresh_job_counts_in_session(session: Session, job: ShortDownloadJob) -> None:
    job.total_items = _count_items(session, job.id)
    job.completed_items = _count_items(session, job.id, "completed")
    job.failed_items = _count_items(session, job.id, "failed")


def _count_items(session: Session, job_id: int, status: str | None = None) -> int:
    query = (
        select(func.count())
        .select_from(ShortDownloadItem)
        .where(ShortDownloadItem.job_id == job_id)
    )
    if status is not None:
        query = query.where(ShortDownloadItem.status == status)
    return int(session.execute(query).scalar_one())


def _get_job(session: Session, job_uuid: str) -> ShortDownloadJob | None:
    return session.execute(
        select(ShortDownloadJob).where(ShortDownloadJob.uuid == job_uuid)
    ).scalar_one_or_none()


def _now() -> datetime:
    return datetime.now(UTC)
