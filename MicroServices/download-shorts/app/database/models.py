from __future__ import annotations

import hashlib
from datetime import datetime
from typing import Any

from sqlalchemy import (
    JSON,
    BigInteger,
    Boolean,
    DateTime,
    ForeignKey,
    Index,
    Integer,
    String,
    Text,
    UniqueConstraint,
    func,
)
from sqlalchemy.orm import Mapped, mapped_column, relationship

from app.database.session import Base


def active_channel_key(channel_url: str) -> str:
    """Stable hash of a channel URL used to enforce one active job per channel.

    Lightly normalized (trailing slash / surrounding whitespace) so that a
    double-submit of the same URL maps to the same key.
    """
    normalized = channel_url.strip().rstrip("/")
    return hashlib.sha256(normalized.encode("utf-8")).hexdigest()


class ShortDownloadJob(Base):
    __tablename__ = "short_download_jobs"

    id: Mapped[int] = mapped_column(BigInteger, primary_key=True, autoincrement=True)
    uuid: Mapped[str] = mapped_column(String(36), unique=True, index=True, nullable=False)
    channel_url: Mapped[str] = mapped_column(Text, nullable=False)
    webhook_url: Mapped[str | None] = mapped_column(Text, nullable=True)

    # Non-null only while the job is active; the unique index then blocks a
    # second concurrent job for the same channel. Cleared to NULL on finish.
    active_channel_key: Mapped[str | None] = mapped_column(String(64), unique=True, nullable=True)

    status: Mapped[str] = mapped_column(String(32), default="queued", nullable=False)
    dispatch_on_complete: Mapped[bool] = mapped_column(Boolean, default=True, nullable=False)
    dispatch_status: Mapped[str] = mapped_column(String(32), default="pending", nullable=False)

    total_items: Mapped[int] = mapped_column(Integer, default=0, nullable=False)
    completed_items: Mapped[int] = mapped_column(Integer, default=0, nullable=False)
    failed_items: Mapped[int] = mapped_column(Integer, default=0, nullable=False)
    last_error: Mapped[str | None] = mapped_column(Text, nullable=True)

    started_at: Mapped[datetime | None] = mapped_column(DateTime(timezone=True), nullable=True)
    finished_at: Mapped[datetime | None] = mapped_column(DateTime(timezone=True), nullable=True)
    created_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), server_default=func.now(), nullable=False
    )
    updated_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True),
        server_default=func.now(),
        onupdate=func.now(),
        nullable=False,
    )

    items: Mapped[list[ShortDownloadItem]] = relationship(
        back_populates="job",
        cascade="all, delete-orphan",
    )


class ShortDownloadItem(Base):
    __tablename__ = "short_download_items"
    __table_args__ = (
        UniqueConstraint("job_id", "youtube_id", name="uq_short_download_items_job_youtube"),
        Index("ix_short_download_items_status", "status"),
        Index("ix_short_download_items_dispatch_status", "dispatch_status"),
        Index("ix_short_download_items_youtube_id", "youtube_id"),
    )

    id: Mapped[int] = mapped_column(BigInteger, primary_key=True, autoincrement=True)
    job_id: Mapped[int] = mapped_column(
        BigInteger,
        ForeignKey("short_download_jobs.id", ondelete="CASCADE"),
        nullable=False,
    )
    youtube_id: Mapped[str] = mapped_column(String(64), nullable=False)
    download_url: Mapped[str] = mapped_column(Text, nullable=False)

    title: Mapped[str | None] = mapped_column(Text, nullable=True)
    description: Mapped[str | None] = mapped_column(Text, nullable=True)
    hashtags: Mapped[list[str]] = mapped_column(JSON, default=list, nullable=False)

    storage_path: Mapped[str | None] = mapped_column(Text, nullable=True)
    storage_size_bytes: Mapped[int | None] = mapped_column(BigInteger, nullable=True)
    storage_mime_type: Mapped[str | None] = mapped_column(String(255), nullable=True)

    status: Mapped[str] = mapped_column(String(32), default="pending", nullable=False)
    dispatch_status: Mapped[str] = mapped_column(String(32), default="pending", nullable=False)
    attempts: Mapped[int] = mapped_column(Integer, default=0, nullable=False)
    last_error: Mapped[str | None] = mapped_column(Text, nullable=True)

    downloaded_at: Mapped[datetime | None] = mapped_column(DateTime(timezone=True), nullable=True)
    uploaded_at: Mapped[datetime | None] = mapped_column(DateTime(timezone=True), nullable=True)
    storage_verified_at: Mapped[datetime | None] = mapped_column(
        DateTime(timezone=True), nullable=True
    )
    created_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), server_default=func.now(), nullable=False
    )
    updated_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True),
        server_default=func.now(),
        onupdate=func.now(),
        nullable=False,
    )

    job: Mapped[ShortDownloadJob] = relationship(back_populates="items")

    def hashtags_list(self) -> list[str]:
        value: Any = self.hashtags
        if isinstance(value, list):
            return [tag for tag in value if isinstance(tag, str) and tag]
        return []
