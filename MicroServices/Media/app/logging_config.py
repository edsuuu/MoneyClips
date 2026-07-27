from __future__ import annotations

import logging
import sys

_LOG_FORMAT = "%(asctime)s | %(levelname)-7s | %(name)s | %(message)s"
_DATE_FORMAT = "%Y-%m-%d %H:%M:%S"


class _SuppressHealthAccessLogs(logging.Filter):
    """Esconde os GET /health no access log do uvicorn (docker healthcheck
    + ping do Laravel quando a /microservices está com auto-atualizar)."""

    def filter(self, record: logging.LogRecord) -> bool:
        message = record.getMessage()
        return "/health" not in message


def configure_logging(level: str = "INFO") -> None:
    log_level = getattr(logging, level.upper(), logging.INFO)

    logger = logging.getLogger("media")
    logger.handlers.clear()

    handler = logging.StreamHandler(sys.stdout)
    handler.setFormatter(logging.Formatter(_LOG_FORMAT, datefmt=_DATE_FORMAT))
    logger.addHandler(handler)
    logger.setLevel(log_level)
    logger.propagate = False

    # uvicorn.access continua imprimindo, mas sem o ruído do healthcheck.
    logging.getLogger("uvicorn.access").addFilter(_SuppressHealthAccessLogs())
