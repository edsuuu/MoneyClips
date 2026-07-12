from __future__ import annotations

import logging
import uuid as uuidlib
from collections.abc import AsyncGenerator
from contextlib import asynccontextmanager
from pathlib import Path

from fastapi import FastAPI, Form, HTTPException, UploadFile
from fastapi.responses import FileResponse, HTMLResponse, JSONResponse

from app.config.settings import settings
from app.jobs.worker import start_processing, store
from app.logging_config import configure_logging
from app.pipeline.variants import ALL_VARIANTS, JobOptions

logger = logging.getLogger("autocaption.api")

_TEMPLATES = Path(__file__).parent / "templates"
_ALLOWED_SUFFIXES = {".mp4", ".mov", ".mkv", ".webm", ".avi", ".m4v"}
_CHUNK = 1024 * 1024


@asynccontextmanager
async def lifespan(_app: FastAPI) -> AsyncGenerator[None, None]:
    configure_logging(settings.log_level)
    logger.info("starting autocaption on %s:%s", settings.api_host, settings.api_port)
    yield


app = FastAPI(
    title="autocaption",
    version="0.1.0",
    description=(
        "Recebe um vídeo, transcreve com WhisperX (GPU) e queima uma legenda "
        "karaokê (palavra falada em amarelo). Saída local por UUID."
    ),
    lifespan=lifespan,
)


@app.get("/health")
def health() -> dict[str, str]:
    return {"status": "ok"}


@app.get("/", response_class=HTMLResponse)
def index() -> HTMLResponse:
    html = (_TEMPLATES / "index.html").read_text(encoding="utf-8")
    return HTMLResponse(content=html)


@app.get("/videos")
def list_videos() -> JSONResponse:
    items = [store.read_status(uid) or {"uuid": uid, "status": "unknown"} for uid in store.list_videos()]
    return JSONResponse(content={"videos": items})


@app.post("/videos", status_code=202)
async def create_video(
    file: UploadFile,
    variants: str = Form(",".join(ALL_VARIANTS)),
    caption_position: str = Form("below"),
    channel_name: str = Form(""),
    channel_handle: str = Form(""),
    subtitle_offset: float = Form(0.0),
    with_captions: bool = Form(True),
) -> JSONResponse:
    suffix = Path(file.filename or "").suffix.lower()
    if suffix not in _ALLOWED_SUFFIXES:
        raise HTTPException(
            status_code=422,
            detail=f"extensão não suportada: {suffix or '(vazia)'}",
        )

    selected = [v.strip() for v in variants.split(",") if v.strip() in ALL_VARIANTS]
    if not selected:
        raise HTTPException(status_code=422, detail="selecione ao menos um modelo")
    options = JobOptions(
        variants=selected,
        caption_position="below" if caption_position == "below" else "inside",
        channel_name=channel_name.strip(),
        channel_handle=channel_handle.strip(),
        subtitle_offset=subtitle_offset,
        with_captions=with_captions,
    )

    uuid = str(uuidlib.uuid4())
    dest = store.source_path(uuid, suffix)
    with dest.open("wb") as out:
        while chunk := await file.read(_CHUNK):
            out.write(chunk)
    logger.info("[%s] vídeo recebido: %s (%s) variants=%s", uuid, file.filename, suffix, selected)

    start_processing(uuid, options)
    return JSONResponse(status_code=202, content={"uuid": uuid, "status": "processing"})


@app.get("/videos/{uuid}")
def video_status(uuid: str) -> JSONResponse:
    status = store.read_status(uuid)
    if status is None:
        raise HTTPException(status_code=404, detail="vídeo não encontrado")
    return JSONResponse(content=status)


@app.get("/videos/{uuid}/output/{variant}")
def video_output(uuid: str, variant: str) -> FileResponse:
    from app.pipeline.variants import FILENAMES

    name = FILENAMES.get(variant)
    if name is None:
        raise HTTPException(status_code=404, detail=f"variante inválida: {variant}")
    path = store.video_dir(uuid) / name
    if not path.exists():
        raise HTTPException(status_code=404, detail="output ainda não disponível")
    return FileResponse(path, media_type="video/mp4", filename=f"{uuid}_{variant}.mp4")


def _open_browser(url: str) -> None:
    """Abre o navegador (best-effort). No WSL abre o navegador do Windows."""
    import shutil
    import subprocess

    for cmd in (["wslview", url], ["xdg-open", url]):
        if shutil.which(cmd[0]):
            try:
                subprocess.Popen(cmd, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
                return
            except Exception:  # noqa: BLE001, S112
                continue
    # WSL → navegador do Windows
    try:
        subprocess.Popen(  # noqa: S603
            ["cmd.exe", "/c", "start", "", url],  # noqa: S607
            stdout=subprocess.DEVNULL,
            stderr=subprocess.DEVNULL,
        )
    except Exception:  # noqa: BLE001, S110
        pass


def run() -> None:
    import threading

    import uvicorn

    configure_logging(settings.log_level)
    url = f"http://127.0.0.1:{settings.api_port}"
    if settings.auto_open:
        threading.Timer(1.5, _open_browser, args=(url,)).start()
        logger.info("abrindo navegador em %s", url)
    uvicorn.run("app.main:app", host=settings.api_host, port=settings.api_port)


if __name__ == "__main__":
    run()
