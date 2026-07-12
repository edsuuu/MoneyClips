from __future__ import annotations

from functools import lru_cache
from typing import Literal

from pydantic_settings import BaseSettings, SettingsConfigDict


class Settings(BaseSettings):
    """Configuração do serviço. Fonte da verdade é o .env / ambiente.

    Campos têm defaults sensatos para o modo dev nativo (venv). Em outros
    microserviços do projeto os defaults vivem só no compose; aqui, como o
    run primário é `python -m app.main` local, mantemos defaults no código.
    """

    model_config = SettingsConfigDict(env_file=".env", extra="ignore")

    api_host: str = "0.0.0.0"
    api_port: int = 8780
    log_level: str = "INFO"
    # abre o navegador automaticamente ao subir o servidor (dev).
    auto_open: bool = True

    # Pasta local onde cada vídeo vira storage/<uuid>/... (sem MinIO).
    storage_dir: str = "./storage"

    # WhisperX.
    whisper_model: str = "large-v3"
    whisper_language: str = "pt"
    whisper_device: str = "cuda"
    whisper_compute_type: str = "float16"
    whisper_batch_size: int = 16

    # Encoder do ffmpeg na queima da legenda. nvenc = GPU; libx264 = CPU.
    gpu_encoder: Literal["nvenc", "libx264"] = "nvenc"

    # Estilo da legenda karaokê.
    max_words_per_line: int = 3
    max_line_duration: float = 2.5
    font_name: str = "Realist Clostan"  # família instalada (face Black Italic)
    font_size: int = 12
    highlight_color: str = "&H0000FFFF&"  # amarelo (AABBGGRR no ASS)

    # Revelação progressiva: cada palavra só aparece quando é falada (evita
    # legenda "adiantada"). Palavras futuras ficam invisíveis mas ocupam espaço
    # (layout estável). False = mostra a linha inteira de uma vez (karaokê puro).
    hide_future_words: bool = True

    # Offset global da legenda em segundos (nudge fino de sincronia; + atrasa).
    subtitle_offset: float = 0.0

    # Marca do template (cabeçalho: logo + @handle apenas).
    channel_name: str = "meu_canal"  # usado só p/ inicial da logo-fallback
    channel_handle: str = "@meu_canal"
    channel_logo: str = "./assets/logo.png"  # gerado se não existir

    # Variantes de saída geradas pelo pipeline.
    # original | vertical (9:16 blur) | template_white | template_black
    output_variants: str = "original,vertical,template_white,template_black"


@lru_cache
def get_settings() -> Settings:
    return Settings()


settings = get_settings()
