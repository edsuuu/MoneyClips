# AGENTS.md

Guidance for Codex when working in this repository.

## What This Project Does

Independent Python microservice for downloading YouTube Shorts from a channel.

It receives a `channel_url`, an optional `webhook_url`, and `dispatch_on_complete`. It lists all Shorts for the channel, stores each item in MySQL as `pending`, downloads videos with worker threads, uploads each file to the configured storage, verifies the uploaded object exists, and dispatches completed items to the webhook automatically or manually. When `webhook_url` is omitted, `dispatch_on_complete` must be `false`: items stay in this service's database (`dispatch_status=pending`) and `/dispatch` requires a `webhook_url` in the request body.

This repository must stay independent from the parent `generate-clips` pipeline. Do not import modules from that project.

## Common Commands

Always use the local virtualenv when available.

```bash
python -m venv .venv
.venv/bin/python -m pip install -r requirements.txt

cp .env.example .env
.venv/bin/alembic upgrade head
.venv/bin/python -m app.main

.venv/bin/python -m compileall app alembic
.venv/bin/alembic upgrade head --sql
```

Default API port is `8770`.

Database configuration uses Laravel-style split env vars. The SQLAlchemy URL is built internally:

```env
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=download_shorts
DB_USERNAME=root
DB_PASSWORD=root
```

```bash
curl http://127.0.0.1:8770/health

curl -X POST http://127.0.0.1:8770/shorts/download \
  -H 'Content-Type: application/json' \
  -d '{
    "channel_url": "https://www.youtube.com/@canal",
    "webhook_url": "https://example.com/api/shorts/callback",
    "dispatch_on_complete": false
  }'

# webhook_url é opcional quando dispatch_on_complete=false:
curl -X POST http://127.0.0.1:8770/shorts/download \
  -H 'Content-Type: application/json' \
  -d '{"channel_url": "https://www.youtube.com/@canal", "dispatch_on_complete": false}'

curl http://127.0.0.1:8770/shorts/download/<job_id>

# webhook_url no dispatch sobrepõe (ou supre, se o job não tiver) o webhook do job:
curl -X POST http://127.0.0.1:8770/shorts/download/<job_id>/dispatch \
  -H 'Content-Type: application/json' \
  -d '{"mode":"batch","batch_size":50,"webhook_url":"https://example.com/api/shorts/callback"}'
```

## Architecture

- `app/main.py` defines the FastAPI routes and startup behavior.
- `app/config/` contains settings and environment parsing.
- `app/database/` contains SQLAlchemy session setup and models.
- `app/jobs/worker.py` lists Shorts, seeds pending items, downloads/uploads/verifies files, and updates status.
- `app/jobs/dispatcher.py` sends completed items to the configured webhook.
- `app/youtube/` wraps `yt_dlp` listing and downloading.
- `app/storage/` wraps S3-compatible storage through `boto3`.
- `alembic/versions/` contains database migrations.

Keep this project simple. Avoid adding extra service layers unless there is a concrete need.

## Data Flow

1. `POST /shorts/download` creates a `short_download_jobs` row.
2. A background thread lists all Shorts for the channel.
3. Each Short is saved in `short_download_items` as `pending`.
4. `ThreadPoolExecutor` processes pending items.
5. Each item must be verified in storage before status becomes `completed`.
6. If `dispatch_on_complete=true`, completed items are dispatched automatically.
7. If `dispatch_on_complete=false`, completed items remain with `dispatch_status=pending` until `/dispatch` is called.

## Important Constraints

- Use generic `storage` terminology in API contracts, docs, and code comments.
- Do not expose provider-specific names in webhook payloads.
- Do not mark an item as `completed` before the storage object exists and has a positive size.
- Keep default concurrency modest. `DOWNLOAD_WORKERS=4` is the default.
- Failed items retry up to `MAX_ATTEMPTS`.
- Webhook failure must not mark downloaded items as failed. Keep `dispatch_status=failed` or `pending` so dispatch can be retried.

## Database

Use Alembic for schema changes.

Tables:

- `short_download_jobs`
- `short_download_items`

For local development, `AUTO_CREATE_TABLES=true` can create tables at startup. For production-like runs, prefer:

```bash
AUTO_CREATE_TABLES=false .venv/bin/alembic upgrade head
```

## Testing And Validation

At minimum, run:

```bash
.venv/bin/python -m compileall app alembic
.venv/bin/alembic upgrade head --sql
```

For integration testing, run MySQL and S3-compatible storage, then create a small download job and verify:

- pending items are inserted;
- downloads run in parallel;
- storage objects exist before items complete;
- manual dispatch sends only completed items with `dispatch_status=pending`.
