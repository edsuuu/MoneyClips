from __future__ import annotations

from functools import lru_cache

from pydantic_settings import BaseSettings, SettingsConfigDict


class Settings(BaseSettings):
    model_config = SettingsConfigDict(env_file=".env", extra="ignore")

    api_host: str = "0.0.0.0"
    api_port: int = 8780
    log_level: str = "INFO"

    whisper_model: str = "large-v3"
    whisper_language: str = "pt"
    whisper_device: str = "cuda"
    whisper_compute_type: str = "float16"


@lru_cache
def get_settings() -> Settings:
    return Settings()


settings = get_settings()
