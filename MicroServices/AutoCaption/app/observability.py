from __future__ import annotations

import contextlib
import logging
import os
import resource
import socket
import sys
import threading
import time
from datetime import UTC, datetime
from typing import Any

import httpx

FLUSH_INTERVAL_SECONDS = 2.0
FLUSH_MAX_ENTRIES = 20
HEARTBEAT_INTERVAL_SECONDS = 30.0
REQUEST_TIMEOUT_SECONDS = 3.0
BUFFER_HARD_CAP = 500

_START_MONOTONIC = time.monotonic()

_LEVELS = {
    logging.DEBUG: "debug",
    logging.INFO: "info",
    logging.WARNING: "warn",
    logging.ERROR: "error",
    logging.CRITICAL: "error",
}


def _memory_mb() -> int:
    usage = resource.getrusage(resource.RUSAGE_SELF).ru_maxrss
    divisor = 1024 * 1024 if sys.platform == "darwin" else 1024
    return int(usage / divisor)


class RemoteLogHandler(logging.Handler):
    def __init__(self, url: str, token: str, service: str) -> None:
        super().__init__()
        self._url = url
        self._token = token
        self._service = service
        self._hostname = socket.gethostname()
        self._buffer: list[dict[str, Any]] = []
        self._lock = threading.Lock()

        thread = threading.Thread(target=self._flush_loop, daemon=True)
        thread.start()

    def emit(self, record: logging.LogRecord) -> None:
        try:
            entry = {
                "level": _LEVELS.get(record.levelno, "info"),
                "message": record.getMessage(),
                "context": None,
                "logged_at": datetime.now(UTC).isoformat(),
            }
        except Exception:
            return

        with self._lock:
            self._buffer.append(entry)
            if len(self._buffer) >= BUFFER_HARD_CAP:
                self._buffer = self._buffer[-FLUSH_MAX_ENTRIES:]

    def _flush_loop(self) -> None:
        while True:
            time.sleep(FLUSH_INTERVAL_SECONDS)
            self._flush()

    def _flush(self) -> None:
        with self._lock:
            if not self._buffer:
                return
            entries, self._buffer = self._buffer, []

        with contextlib.suppress(Exception):
            httpx.post(
                f"{self._url}/logs",
                json={"service": self._service, "hostname": self._hostname, "entries": entries},
                headers={"X-Observability-Token": self._token},
                timeout=REQUEST_TIMEOUT_SECONDS,
            )


def _heartbeat_loop(url: str, token: str, service: str) -> None:
    hostname = socket.gethostname()
    while True:
        with contextlib.suppress(Exception):
            httpx.post(
                f"{url}/heartbeat",
                json={
                    "service": service,
                    "hostname": hostname,
                    "uptime_seconds": int(time.monotonic() - _START_MONOTONIC),
                    "memory_mb": _memory_mb(),
                },
                headers={"X-Observability-Token": token},
                timeout=REQUEST_TIMEOUT_SECONDS,
            )
        time.sleep(HEARTBEAT_INTERVAL_SECONDS)


def start_observability(service_name: str, logger_name: str) -> None:
    url = os.getenv("OBSERVABILITY_URL", "").rstrip("/")
    token = os.getenv("OBSERVABILITY_TOKEN", "")
    service = os.getenv("SERVICE_NAME", service_name)
    logger = logging.getLogger(logger_name)

    if not url or not token:
        logger.warning(
            "[Observability] OBSERVABILITY_URL/OBSERVABILITY_TOKEN não configurados — "
            "push remoto desativado."
        )
        return

    handler = RemoteLogHandler(url, token, service)
    handler.setLevel(logging.DEBUG)
    logger.addHandler(handler)

    threading.Thread(target=_heartbeat_loop, args=(url, token, service), daemon=True).start()
    logger.info("[Observability] Push remoto ligado (%s → %s).", service, url)
