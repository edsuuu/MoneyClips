"""Observabilidade remota: handler de logging que empilha
as linhas num buffer e as envia em lote ao Laravel (POST
/api/observability/logs, flush a cada 2s).

Regra de ouro: FIRE-AND-FORGET. Timeout curto, erro descartado — o envio de
log nunca pode derrubar ou atrasar o serviço. O console (pm2) continua sendo
a saída primária.
"""

from __future__ import annotations

import contextlib
import logging
import os
import socket
import threading
import time
from datetime import UTC, datetime
from typing import Any

import httpx

FLUSH_INTERVAL_SECONDS = 2.0
FLUSH_MAX_ENTRIES = 20
REQUEST_TIMEOUT_SECONDS = 3.0
BUFFER_HARD_CAP = 500

_LEVELS = {
    logging.DEBUG: "debug",
    logging.INFO: "info",
    logging.WARNING: "warn",
    logging.ERROR: "error",
    logging.CRITICAL: "error",
}


class RemoteLogHandler(logging.Handler):
    """Só bufferiza no emit(); quem envia é a thread de flush (nunca bloqueia
    o caller do logging)."""

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
                # backpressure: descarta os antigos
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

        # Laravel fora do ar: descarta e segue.
        with contextlib.suppress(Exception):
            httpx.post(
                f"{self._url}/logs",
                json={"service": self._service, "hostname": self._hostname, "entries": entries},
                headers={"X-Observability-Token": self._token},
                timeout=REQUEST_TIMEOUT_SECONDS,
            )


def start_observability(
    service_name: str,
    logger_name: str,
    *,
    url: str = "",
    token: str = "",
) -> None:
    """Liga o push remoto no logger do serviço. Sem OBSERVABILITY_URL/TOKEN
    configurados, não faz nada (log local continua normal). Env var exportada
    vence; senão usa o que veio das settings (que leem o .env do serviço, que
    o `make up`/pm2 não exporta pro ambiente)."""
    url = (os.getenv("OBSERVABILITY_URL") or url).rstrip("/")
    token = os.getenv("OBSERVABILITY_TOKEN") or token
    service = os.getenv("SERVICE_NAME") or service_name
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

    logger.info("[Observability] Push remoto ligado (%s → %s).", service, url)
