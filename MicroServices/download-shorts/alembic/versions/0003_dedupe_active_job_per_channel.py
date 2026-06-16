"""dedupe active job per channel

Revision ID: 0003
Revises: 0002
Create Date: 2026-06-13
"""

import sqlalchemy as sa

from alembic import op

revision = "0003"
down_revision = "0002"
branch_labels = None
depends_on = None


def upgrade() -> None:
    # Holds a hash of the channel_url while the job is active (queued/listing/
    # processing) and is set back to NULL once it reaches a terminal state.
    # MySQL allows multiple NULLs in a unique index, so completed channels can
    # be re-run while at most one *active* job per channel may exist at a time.
    op.add_column(
        "short_download_jobs",
        sa.Column("active_channel_key", sa.String(length=64), nullable=True),
    )
    op.create_index(
        "uq_short_download_jobs_active_channel_key",
        "short_download_jobs",
        ["active_channel_key"],
        unique=True,
    )


def downgrade() -> None:
    op.drop_index(
        "uq_short_download_jobs_active_channel_key",
        table_name="short_download_jobs",
    )
    op.drop_column("short_download_jobs", "active_channel_key")
