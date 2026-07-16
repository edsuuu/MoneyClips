"""Envio de webhook de conclusão pro Laravel (fire-and-forget com retry).

Mesmo padrão do download-shorts: 3 re-tentativas com backoff; se o Laravel
estiver fora do ar, loga e desiste — o webhook nunca derruba o pipeline
(o status continua consultável em GET /videos/{uuid}).
"""

from __future__ import annotations

import logging
import time

import httpx

logger = logging.getLogger("autocaption.webhook")

_RETRY_DELAYS = (0.0, 1.0, 5.0, 15.0)
_TIMEOUT_SECONDS = 30.0


def send_webhook(url: str, payload: dict) -> bool:
    for attempt, delay in enumerate(_RETRY_DELAYS, start=1):
        if delay:
            time.sleep(delay)
        try:
            response = httpx.post(url, json=payload, timeout=_TIMEOUT_SECONDS)
            response.raise_for_status()
            logger.info("webhook aceito (%s) em %s", response.status_code, url)
            return True
        except Exception as exc:  # noqa: BLE001
            logger.warning("webhook falhou (tentativa %d/%d): %s", attempt, len(_RETRY_DELAYS), exc)
    logger.error("webhook desistiu após %d tentativas: %s", len(_RETRY_DELAYS), url)
    return False
