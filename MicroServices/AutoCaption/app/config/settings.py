from __future__ import annotations

from functools import lru_cache
from typing import Literal

from pydantic_settings import BaseSettings, SettingsConfigDict


class Settings(BaseSettings):
    model_config = SettingsConfigDict(env_file=".env", extra="ignore")

    api_host: str = "0.0.0.0"
    api_port: int = 8780
    log_level: str = "INFO"
    auto_open: bool = True

    storage_dir: str = "./storage"

    whisper_model: str = "large-v3"
    whisper_language: str = "pt"
    whisper_device: str = "cuda"
    whisper_compute_type: str = "float16"
    whisper_batch_size: int = 16

    gpu_encoder: Literal["nvenc", "libx264"] = "nvenc"

    max_words_per_line: int = 3
    max_line_duration: float = 2.5
    font_name: str = "Realist Clostan"
    font_size: int = 12
    highlight_color: str = "&H0000FFFF&"

    hide_future_words: bool = True

    subtitle_offset: float = 0.0

    channel_name: str = "meu_canal"
    channel_handle: str = "@meu_canal"
    channel_logo: str = "./assets/logo.png"
    template_font_path: str = ""
    watermark_text: str = ""

    output_variants: str = "original,vertical,template_white,template_black"


@lru_cache
def get_settings() -> Settings:
    return Settings()


settings = get_settings()
