from __future__ import annotations

import logging
import sys

_LOG_FORMAT = "%(asctime)s | %(levelname)-7s | %(name)s | %(message)s"
_DATE_FORMAT = "%Y-%m-%d %H:%M:%S"


def configure_logging(level: str = "INFO") -> None:
    """Send the whole ``shorts.*`` pipeline (worker, dispatcher, youtube,
    storage) to stdout so it shows up in ``docker logs``.

    Idempotent: safe to call from both ``run()`` and the FastAPI lifespan.
    The handler is attached to the ``shorts`` parent logger and ``propagate``
    is disabled so records are not also emitted through uvicorn's root config.
    """
    log_level = getattr(logging, level.upper(), logging.INFO)

    logger = logging.getLogger("shorts")
    logger.handlers.clear()

    handler = logging.StreamHandler(sys.stdout)
    handler.setFormatter(logging.Formatter(_LOG_FORMAT, datefmt=_DATE_FORMAT))
    logger.addHandler(handler)
    logger.setLevel(log_level)
    logger.propagate = False
