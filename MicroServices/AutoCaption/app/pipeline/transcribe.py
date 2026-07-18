from __future__ import annotations

import json
import logging
from pathlib import Path
from typing import Any

from app.config.settings import settings

logger = logging.getLogger("autocaption.pipeline.transcribe")

_model: Any = None


def _load_model() -> Any:
    global _model  # noqa: PLW0603
    if _model is None:
        from faster_whisper import WhisperModel

        logger.info(
            "carregando faster-whisper %s (device=%s, compute=%s)",
            settings.whisper_model,
            settings.whisper_device,
            settings.whisper_compute_type,
        )
        _model = WhisperModel(
            settings.whisper_model,
            device=settings.whisper_device,
            compute_type=settings.whisper_compute_type,
        )
    return _model


def transcribe(audio_path: Path, transcript_out: Path) -> dict[str, Any]:
    model = _load_model()
    logger.info("transcrevendo %s", audio_path.name)
    segments_gen, info = model.transcribe(
        str(audio_path),
        language=settings.whisper_language,
        word_timestamps=True,
        vad_filter=True,
        beam_size=5,
    )

    segments: list[dict[str, Any]] = []
    for seg in segments_gen:
        words = []
        for w in seg.words or []:
            words.append(
                {
                    "word": w.word,
                    "start": w.start,
                    "end": w.end,
                    "score": getattr(w, "probability", None),
                }
            )
        segments.append(
            {
                "start": seg.start,
                "end": seg.end,
                "text": seg.text,
                "words": words,
            }
        )

    aligned = {"segments": segments, "language": info.language}
    transcript_out.write_text(
        json.dumps(aligned, ensure_ascii=False, indent=2), encoding="utf-8"
    )
    n_words = sum(len(s["words"]) for s in segments)
    logger.info("transcrição concluída: %d segmentos, %d palavras", len(segments), n_words)
    return aligned
