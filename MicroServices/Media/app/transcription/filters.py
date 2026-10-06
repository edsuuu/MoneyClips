from __future__ import annotations

import re

# ponytail: lista fixa das alucinações mais comuns do whisper em trecho sem fala; crescer só com caso real.
_NON_SPEECH = {"música", "musica", "aplausos", "risos", "legendas pela comunidade amara org"}


def normalize(text: str) -> str:
    return re.sub(r"[\[\]()♪.!?,\s]+", " ", text.lower()).strip()


def is_hallucination(text: str, previous_text: str | None) -> bool:
    normalized = normalize(text)
    if not normalized or normalized in _NON_SPEECH:
        return True

    return normalized == previous_text


if __name__ == "__main__":
    assert is_hallucination(" Música", None)
    assert is_hallucination("[Música]", None)
    assert is_hallucination("♪ ♪", None)
    assert is_hallucination("Legendas pela comunidade Amara.org", None)
    assert is_hallucination("Olá, tudo bem?", normalize("Olá tudo bem"))
    assert not is_hallucination("Olá, tudo bem?", None)
    assert not is_hallucination("Música para meus ouvidos", None)
