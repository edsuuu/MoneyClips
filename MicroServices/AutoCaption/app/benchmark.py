"""Benchmark do pipeline completo: para cada vídeo mede áudio + transcrição +
cada variante, e escreve BENCHMARKS.md.

Uso:
    python -m app.benchmark <video1.mp4> [<video2.mp4> ...]

Sem argumentos, reprocessa os sources já presentes em storage/<label>/source.*.
"""
# ruff: noqa: T201

from __future__ import annotations

import re
import shutil
import subprocess
import sys
import time
from datetime import datetime
from pathlib import Path
from typing import Any
from zoneinfo import ZoneInfo

from app.config.settings import settings
from app.pipeline.audio import extract_audio, probe_resolution
from app.pipeline.transcribe import transcribe
from app.pipeline.variants import (
    FILENAMES,
    JobOptions,
    generate_all,
    write_video_benchmark,
)
from app.storage.local import VideoStore

_TZ = ZoneInfo("America/Sao_Paulo")


def _slug(name: str) -> str:
    s = re.sub(r"[^a-z0-9]+", "-", Path(name).stem.lower()).strip("-")
    return s or "video"


def _media_dur(p: Path) -> float:
    o = subprocess.run(  # noqa: S603
        ["ffprobe", "-v", "error", "-show_entries", "format=duration",  # noqa: S607
         "-of", "default=nk=1:nw=1", str(p)],
        capture_output=True, text=True, check=True,
    )
    return float(o.stdout.strip())


def _size_mb(p: Path) -> float:
    return p.stat().st_size / (1024 * 1024)


def _gpu_name() -> str:
    try:
        o = subprocess.run(  # noqa: S603
            ["nvidia-smi", "--query-gpu=name", "--format=csv,noheader"],  # noqa: S607
            capture_output=True, text=True, check=True,
        )
        return o.stdout.strip().splitlines()[0]
    except Exception:  # noqa: BLE001
        return "unknown"


def _resolve_inputs(args: list[str], store: VideoStore) -> dict[str, Path | None]:
    """label -> source path (None = já está em storage)."""
    if args:
        return {_slug(a): Path(a) for a in args}
    labels = {}
    for label in store.list_videos():
        src = store.find_source(label)
        if src is not None:
            labels[label] = None
    return labels


def run(labels: dict[str, Path | None], store: VideoStore) -> dict[str, Any]:
    reports: dict[str, Any] = {}
    for label, path in labels.items():
        if path is not None:
            shutil.copy(path, store.source_path(label, path.suffix.lower()))
        src = store.find_source(label)
        if src is None:
            print(f"[{label}] sem source, pulando")
            continue
        sw, sh = probe_resolution(src)
        dur = _media_dur(src)
        print(f"[{label}] {sw}x{sh} {dur:.1f}s — extraindo áudio…")
        t = time.perf_counter(); extract_audio(src, store.audio_path(label)); t_audio = time.perf_counter() - t
        print(f"[{label}] transcrevendo…")
        t = time.perf_counter(); aligned = transcribe(store.audio_path(label), store.transcript_path(label)); t_tr = time.perf_counter() - t
        nwords = sum(len(s.get("words", [])) for s in aligned["segments"])
        print(f"[{label}] transcrição {t_tr:.1f}s ({nwords} palavras) — gerando variantes…")
        variants = generate_all(label, aligned, src, store, JobOptions())
        sizes = {k: _size_mb(store.video_dir(label) / FILENAMES[k]) for k in variants}
        reports[label] = {"resolution": f"{sw}x{sh}", "duration_s": dur, "n_words": nwords,
                          "extract_audio": t_audio, "transcribe": t_tr, "variants": variants, "sizes": sizes}
        write_video_benchmark(
            store, label, resolution=f"{sw}x{sh}", duration=dur, n_words=nwords,
            t_audio=t_audio, t_transcribe=t_tr, variant_timings=variants, sizes=sizes,
        )
        print(f"[{label}] OK: {variants}")
    return reports


def write_report(reports: dict[str, Any], out: Path) -> None:
    uids = list(reports.keys())
    L: list[str] = []
    L.append("# AutoCaption — Benchmarks")
    L.append("")
    L.append(f"- **Data:** {datetime.now(_TZ).strftime('%Y-%m-%d %H:%M %Z')}")
    L.append(f"- **GPU:** {_gpu_name()}")
    L.append(f"- **Transcrição:** faster-whisper `{settings.whisper_model}` ({settings.whisper_language}), compute `{settings.whisper_compute_type}`, word_timestamps + VAD")
    L.append(f"- **Encoder:** {settings.gpu_encoder} qualidade máxima (NVENC p7, tune hq, CQ 16) · **Fonte:** {settings.font_name} (size {settings.font_size})")
    L.append("")
    L.append("RTF = tempo de processamento ÷ duração do vídeo (< 1.0 = mais rápido que tempo real).")
    L.append("")
    L.append("| Processo | " + " | ".join(uids) + " |")
    L.append("|" + "---|" * (len(uids) + 1))

    def rowv(label: str, key: str, variant: bool = False) -> str:
        cells = []
        for u in uids:
            r = reports[u]
            v = r["variants"].get(key) if variant else r.get(key)
            cells.append("—" if v is None else f"{v:.1f}s (RTF {v / r['duration_s']:.2f})")
        return f"| {label} | " + " | ".join(cells) + " |"

    L.append("| **Resolução** | " + " | ".join(reports[u]["resolution"] for u in uids) + " |")
    L.append("| **Duração** | " + " | ".join(f"{reports[u]['duration_s']:.1f}s" for u in uids) + " |")
    L.append("| **Palavras** | " + " | ".join(str(reports[u]["n_words"]) for u in uids) + " |")
    L.append(rowv("Extração de áudio", "extract_audio"))
    L.append(rowv("Transcrição (faster-whisper)", "transcribe"))
    L.append(rowv("Variante: original", "original", True))
    L.append(rowv("Variante: vertical 9:16 (blur)", "vertical", True))
    L.append(rowv("Variante: template branco", "template_white", True))
    L.append(rowv("Variante: template preto", "template_black", True))
    tot = []
    for u in uids:
        r = reports[u]
        s = r["extract_audio"] + r["transcribe"] + sum(r["variants"].values())
        tot.append(f"{s:.1f}s (RTF {s / r['duration_s']:.2f})")
    L.append("| **TOTAL (áudio+transcrição+4 variantes)** | " + " | ".join(tot) + " |")
    L.append("")
    L.append("### Tamanho dos arquivos gerados")
    L.append("")
    L.append("| Variante | " + " | ".join(uids) + " |")
    L.append("|" + "---|" * (len(uids) + 1))
    for k, lbl in [("original", "original"), ("vertical", "vertical 9:16"),
                   ("template_white", "template branco"), ("template_black", "template preto")]:
        L.append(f"| {lbl} | " + " | ".join(f"{reports[u]['sizes'].get(k, 0):.1f} MB" for u in uids) + " |")
    L.append("")
    L.append("> Transcrição inclui o load do modelo na 1ª execução (amortizado com o serviço no ar). "
             "A variante 9:16 é a mais cara (desfoque de fundo em CPU).")
    L.append("")
    out.write_text("\n".join(L) + "\n", encoding="utf-8")


def main() -> None:
    store = VideoStore()
    labels = _resolve_inputs(sys.argv[1:], store)
    if not labels:
        print("nenhum vídeo. Uso: python -m app.benchmark <video1> [<video2> ...]")
        return
    reports = run(labels, store)
    if not reports:
        print("nada processado.")
        return
    out = Path("BENCHMARKS.md")
    write_report(reports, out)
    print(f"\nBENCHMARKS.md escrito ({out.resolve()}).")


if __name__ == "__main__":
    main()
