from functools import lru_cache
from pathlib import Path

from pydantic_settings import BaseSettings, SettingsConfigDict


class Settings(BaseSettings):
    model_config = SettingsConfigDict(
        env_file=".env",
        env_file_encoding="utf-8",
        extra="ignore",
    )

    api_host: str = "0.0.0.0"
    api_port: int = 8770

    log_level: str = "INFO"

    storage_endpoint: str = "http://127.0.0.1:9000"
    storage_access_key: str = "storageadmin"
    storage_secret_key: str = "storageadmin"
    storage_bucket: str = "auto-post"
    storage_secure: bool = False
    storage_region: str = "us-east-1"
    storage_use_path_style: bool = True
    storage_path_prefix: str = "shorts"

    temp_dir: Path = Path("/tmp/download-shorts")
    download_workers: int = 4
    max_attempts: int = 3

    webhook_timeout_seconds: float = 30.0
    webhook_retry_delays_seconds: tuple[float, ...] = (1.0, 5.0, 15.0)


@lru_cache
def get_settings() -> Settings:
    return Settings()


settings = get_settings()
