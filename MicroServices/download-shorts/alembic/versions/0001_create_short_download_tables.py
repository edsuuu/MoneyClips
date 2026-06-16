"""create short download tables

Revision ID: 0001
Revises:
Create Date: 2026-06-12
"""

import sqlalchemy as sa

from alembic import op

revision = "0001"
down_revision = None
branch_labels = None
depends_on = None


def upgrade() -> None:
    op.create_table(
        "short_download_jobs",
        sa.Column("id", sa.BigInteger(), primary_key=True, autoincrement=True),
        sa.Column("uuid", sa.String(length=36), nullable=False),
        sa.Column("channel_url", sa.Text(), nullable=False),
        sa.Column("webhook_url", sa.Text(), nullable=False),
        sa.Column("status", sa.String(length=32), nullable=False, server_default="queued"),
        sa.Column("dispatch_on_complete", sa.Boolean(), nullable=False, server_default=sa.true()),
        sa.Column(
            "dispatch_status", sa.String(length=32), nullable=False, server_default="pending"
        ),
        sa.Column("total_items", sa.Integer(), nullable=False, server_default="0"),
        sa.Column("completed_items", sa.Integer(), nullable=False, server_default="0"),
        sa.Column("failed_items", sa.Integer(), nullable=False, server_default="0"),
        sa.Column("last_error", sa.Text(), nullable=True),
        sa.Column("started_at", sa.DateTime(timezone=True), nullable=True),
        sa.Column("finished_at", sa.DateTime(timezone=True), nullable=True),
        sa.Column(
            "created_at", sa.DateTime(timezone=True), nullable=False, server_default=sa.func.now()
        ),
        sa.Column(
            "updated_at",
            sa.DateTime(timezone=True),
            nullable=False,
            server_default=sa.func.now(),
            server_onupdate=sa.func.now(),
        ),
    )
    op.create_index("ix_short_download_jobs_uuid", "short_download_jobs", ["uuid"], unique=True)

    op.create_table(
        "short_download_items",
        sa.Column("id", sa.BigInteger(), primary_key=True, autoincrement=True),
        sa.Column("job_id", sa.BigInteger(), nullable=False),
        sa.Column("youtube_id", sa.String(length=64), nullable=False),
        sa.Column("download_url", sa.Text(), nullable=False),
        sa.Column("title", sa.Text(), nullable=True),
        sa.Column("description", sa.Text(), nullable=True),
        sa.Column("hashtags", sa.JSON(), nullable=False),
        sa.Column("storage_path", sa.Text(), nullable=True),
        sa.Column("storage_size_bytes", sa.BigInteger(), nullable=True),
        sa.Column("storage_mime_type", sa.String(length=255), nullable=True),
        sa.Column("status", sa.String(length=32), nullable=False, server_default="pending"),
        sa.Column(
            "dispatch_status", sa.String(length=32), nullable=False, server_default="pending"
        ),
        sa.Column("attempts", sa.Integer(), nullable=False, server_default="0"),
        sa.Column("last_error", sa.Text(), nullable=True),
        sa.Column("downloaded_at", sa.DateTime(timezone=True), nullable=True),
        sa.Column("uploaded_at", sa.DateTime(timezone=True), nullable=True),
        sa.Column("storage_verified_at", sa.DateTime(timezone=True), nullable=True),
        sa.Column(
            "created_at", sa.DateTime(timezone=True), nullable=False, server_default=sa.func.now()
        ),
        sa.Column(
            "updated_at",
            sa.DateTime(timezone=True),
            nullable=False,
            server_default=sa.func.now(),
            server_onupdate=sa.func.now(),
        ),
        sa.ForeignKeyConstraint(["job_id"], ["short_download_jobs.id"], ondelete="CASCADE"),
        sa.UniqueConstraint("job_id", "youtube_id", name="uq_short_download_items_job_youtube"),
    )
    op.create_index("ix_short_download_items_status", "short_download_items", ["status"])
    op.create_index(
        "ix_short_download_items_dispatch_status",
        "short_download_items",
        ["dispatch_status"],
    )
    op.create_index("ix_short_download_items_youtube_id", "short_download_items", ["youtube_id"])


def downgrade() -> None:
    op.drop_index("ix_short_download_items_youtube_id", table_name="short_download_items")
    op.drop_index("ix_short_download_items_dispatch_status", table_name="short_download_items")
    op.drop_index("ix_short_download_items_status", table_name="short_download_items")
    op.drop_table("short_download_items")
    op.drop_index("ix_short_download_jobs_uuid", table_name="short_download_jobs")
    op.drop_table("short_download_jobs")
