from functools import lru_cache
from pathlib import Path
from typing import Literal

from pydantic_settings import BaseSettings, SettingsConfigDict


class Settings(BaseSettings):
    model_config = SettingsConfigDict(
        env_file=".env",
        env_file_encoding="utf-8",
        extra="ignore",
    )

    api_host: str
    api_port: int

    log_level: str
    observability_token: str = ""

    storage_endpoint: str
    storage_access_key: str
    storage_secret_key: str
    storage_bucket: str
    storage_secure: bool
    storage_region: str
    storage_use_path_style: bool
    storage_path_prefix: str

    temp_dir: Path
    download_workers: int
    max_attempts: int

    webhook_timeout_seconds: float
    webhook_retry_delays_seconds: list[float]

    gpu_encoder: Literal["none", "nvenc", "videotoolbox"]


@lru_cache
def get_settings() -> Settings:
    return Settings()


settings = get_settings()
