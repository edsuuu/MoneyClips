from __future__ import annotations

import logging
import sys

_FORMAT = "%(asctime)s | %(levelname)-7s | %(name)s | %(message)s"


class _HealthAccessFilter(logging.Filter):
    def filter(self, record: logging.LogRecord) -> bool:
        message = record.getMessage()
        return "/health" not in message


def configure_logging(level: str) -> None:
    handler = logging.StreamHandler(sys.stdout)
    handler.setFormatter(logging.Formatter(_FORMAT))

    root = logging.getLogger("transcriber")
    root.handlers.clear()
    root.addHandler(handler)
    root.setLevel(level.upper())
    root.propagate = False

    logging.getLogger("uvicorn.access").addFilter(_HealthAccessFilter())
