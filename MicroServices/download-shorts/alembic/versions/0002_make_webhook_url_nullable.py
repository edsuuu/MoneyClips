"""make webhook_url nullable

Revision ID: 0002
Revises: 0001
Create Date: 2026-06-12
"""

import sqlalchemy as sa

from alembic import op

revision = "0002"
down_revision = "0001"
branch_labels = None
depends_on = None


def upgrade() -> None:
    op.alter_column(
        "short_download_jobs",
        "webhook_url",
        existing_type=sa.Text(),
        nullable=True,
    )


def downgrade() -> None:
    op.execute("UPDATE short_download_jobs SET webhook_url = '' WHERE webhook_url IS NULL")
    op.alter_column(
        "short_download_jobs",
        "webhook_url",
        existing_type=sa.Text(),
        nullable=False,
    )
