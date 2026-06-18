from functools import lru_cache
from pathlib import Path

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
    # Atrasos (segundos) entre tentativas de webhook. Passar como JSON no env:
    # WEBHOOK_RETRY_DELAYS_SECONDS=[1.0,5.0,15.0]
    webhook_retry_delays_seconds: list[float]


@lru_cache
def get_settings() -> Settings:
    return Settings()


settings = get_settings()
