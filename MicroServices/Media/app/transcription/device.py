"""Resolução de device do faster-whisper por sistema operacional.

O backend CTranslate2 só suporta CPU e CUDA — não há Metal/MPS. Então em macOS
(desenvolvimento) roda em CPU; na máquina de produção (Linux + NVIDIA) roda em
CUDA. A função é pura (recebe o `system`) pra ser testável sem mexer no ambiente.
"""

from __future__ import annotations


def resolve_device(system: str, device: str, compute_type: str) -> tuple[str, str]:
    if system == "Darwin":
        return "cpu", "int8"

    return device, compute_type


if __name__ == "__main__":
    _cases = [
        (("Darwin", "cuda", "float16"), ("cpu", "int8")),
        (("Linux", "cuda", "float16"), ("cuda", "float16")),
        (("Linux", "cpu", "int8"), ("cpu", "int8")),
    ]
    for _args, _expected in _cases:
        _got = resolve_device(*_args)
        if _got != _expected:
            raise SystemExit(f"resolve_device{_args} = {_got}, esperado {_expected}")
