from functools import lru_cache
from pathlib import Path
from urllib.parse import quote_plus

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

    db_host: str = "127.0.0.1"
    db_port: int = 3306
    db_database: str = "download_shorts"
    db_username: str = "root"
    db_password: str = "root"
    auto_create_tables: bool = True

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

    webhook_timeout_seconds: float = 60.0

    @property
    def database_url(self) -> str:
        username = quote_plus(self.db_username)
        password = quote_plus(self.db_password)
        database = quote_plus(self.db_database)
        return (
            f"mysql+pymysql://{username}:{password}"
            f"@{self.db_host}:{self.db_port}/{database}?charset=utf8mb4"
        )


@lru_cache
def get_settings() -> Settings:
    return Settings()


settings = get_settings()
