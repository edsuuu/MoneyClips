# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this service is

Independent Python (FastAPI) microservice that downloads all YouTube Shorts of a channel, uploads each file to S3-compatible storage, verifies the object exists, and dispatches completed items to a webhook (automatically or on demand). Default port `8770`.

**Hard constraint:** this repo must stay independent from the parent `generate-clips` pipeline — never import its modules. In API contracts, payloads, docs, and comments use generic `storage` terminology; do not expose provider-specific names (the real backend is Contabo S3, but that stays in `.env`). See `AGENTS.md` for the full constraint list.

## Commands

Always use the local virtualenv (`.venv/bin/...`).

```bash
# Setup
python -m venv .venv
.venv/bin/python -m pip install -r requirements.txt
cp .env.example .env
.venv/bin/alembic upgrade head

# Run
.venv/bin/python -m app.main          # serves on $API_HOST:$API_PORT (default 8770)

# Lint / format / types (pre-commit gate)
.venv/bin/ruff check app alembic
.venv/bin/ruff format app alembic
.venv/bin/mypy app                    # alembic/ is excluded; mypy disallow_untyped_defs is on

# Minimum validation (there is no test suite)
.venv/bin/python -m compileall app alembic
.venv/bin/alembic upgrade head --sql  # render migrations without touching the DB

# Migrations
.venv/bin/alembic revision -m "description"
.venv/bin/alembic upgrade head
```

Docker (MySQL and storage are external services, credentials from `.env`):

```bash
docker compose --profile local up --build   # built image + alembic upgrade + app
docker compose --profile dev up             # mounted volume, uvicorn --reload, 2 workers
```

There is **no automated test suite**. Validate by compiling, rendering migrations, and running an integration job against real MySQL + storage.

## Configuration

Settings load from `.env` via pydantic-settings (`app/config/settings.py`). DB config uses Laravel-style split vars (`DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`); the SQLAlchemy URL (`mysql+pymysql`) is built internally in `Settings.database_url`. Storage uses `STORAGE_*` vars; worker tuning via `DOWNLOAD_WORKERS` (default 4), `MAX_ATTEMPTS` (default 3), `WEBHOOK_TIMEOUT_SECONDS`.

`AUTO_CREATE_TABLES=true` creates tables at startup (dev only). For production set it `false` and run `alembic upgrade head`.

## Architecture

Request → DB row → background thread → thread pool → optional webhook. Everything is persisted in MySQL so jobs survive restarts; there is no external queue/broker.

- `app/main.py` — FastAPI routes + `lifespan` startup. On boot it calls `resume_unfinished_jobs()` to relaunch any job left in `queued`/`listing`/`processing`.
- `app/jobs/worker.py` — the core engine. `start_job()` spawns a daemon thread guarded by an in-process `_running_jobs` set (prevents the same job running twice in one process). `process_job` runs the pipeline: `_seed_items` → `_reset_active_items` → `_process_items` (`ThreadPoolExecutor`, `DOWNLOAD_WORKERS` threads) → `_finish_job`.
- `app/jobs/dispatcher.py` — `dispatch_completed_items()` posts completed+`dispatch_status=pending` items to the webhook (`mode="batch"` with `batch_size`, or `"all"`).
- `app/youtube/client.py` — `yt_dlp` wrappers: `list_shorts()`, `download_short()`.
- `app/storage/client.py` — `boto3` S3 wrapper (`exists`, `stat`, `upload_file`) and `storage_path_for(youtube_id)`.
- `app/database/` — `session.py` (engine, `SessionLocal`, `Base`, `init_db`) and `models.py`.
- `alembic/versions/` — migrations.

### Job & item state machines (the load-bearing logic)

A `ShortDownloadJob` (1) has many `ShortDownloadItem` (N), unique on `(job_id, youtube_id)`. Each request creates one job; each Short becomes one item.

Job status: `queued → listing → processing → completed | completed_partial | failed`. `_finish_job` picks the terminal state from item counts (all ok → `completed`; some completed + some failed → `completed_partial`; none completed → `failed`).

Item status: `pending → downloading → uploading → verifying → completed | failed`.

Critical invariants when editing the worker — keep these intact:

- **An item is `completed` only after the storage object exists with positive size.** `_process_item` re-stats after upload and raises if `size_bytes <= 0`. If the object already exists before download, it short-circuits to completed (idempotent re-runs).
- **Retries:** each attempt increments `attempts`; on failure the item goes back to `pending` (and sleeps 1s) while `attempts < MAX_ATTEMPTS`, otherwise `failed`.
- **`_reset_active_items`** flips any item stuck in `downloading/uploading/verifying` (from a crash) back to `pending` before reprocessing, making restart safe.
- **Webhook failure must never fail downloaded items.** Dispatch errors set `dispatch_status` to `failed`/`partial` (job + items stay intact) so dispatch can be retried; `_finish_job` swallows auto-dispatch exceptions.

### Webhook decoupling

`webhook_url` is optional. When omitted, `dispatch_on_complete` must be `false` — items stay in this DB with `dispatch_status=pending`, and `/dispatch` then requires a `webhook_url` in the request body (which overrides/supplies the job's). This supports an external orchestrator (Laravel) that pulls from `GET /shorts/items` instead of receiving pushes. Dispatch tracking: item `dispatch_status` `pending → dispatched`; job `dispatch_status` `pending → partial → dispatched` (or `failed`).

## Endpoints

- `GET /health`
- `POST /shorts/download` — `{channel_url, webhook_url?, dispatch_on_complete}` → `202 {job_id, status}`
- `GET /shorts/download/{job_id}` — status + per-status item counts
- `GET /shorts/items?status=completed&limit=&offset=` — items deduped by `youtube_id` (latest kept); for external orchestrators
- `POST /shorts/download/{job_id}/dispatch` — `{mode: batch|all, batch_size?, webhook_url?}`
