import json
from pathlib import Path
from typing import Any

import boto3
from botocore.client import Config
from botocore.exceptions import ClientError

from app.config.settings import settings


def _storage_folder_for(youtube_id: str) -> str:
    prefix = settings.storage_path_prefix.strip("/")
    return f"{prefix}/{youtube_id}" if prefix else youtube_id


def storage_path_for(youtube_id: str) -> str:
    return f"{_storage_folder_for(youtube_id)}/short_{youtube_id}.mp4"


def metadata_storage_path_for(youtube_id: str) -> str:
    return f"{_storage_folder_for(youtube_id)}/{youtube_id}.json"


class StorageClient:
    def __init__(self) -> None:
        self.bucket = settings.storage_bucket
        self.client = boto3.client(
            "s3",
            endpoint_url=settings.storage_endpoint,
            aws_access_key_id=settings.storage_access_key,
            aws_secret_access_key=settings.storage_secret_key,
            region_name=settings.storage_region,
            use_ssl=settings.storage_secure,
            config=Config(
                signature_version="s3v4",
                s3={"addressing_style": "path" if settings.storage_use_path_style else "auto"},
            ),
        )
        self._ensure_bucket()

    def _ensure_bucket(self) -> None:
        try:
            self.client.head_bucket(Bucket=self.bucket)
        except ClientError as exc:
            code = str(exc.response.get("Error", {}).get("Code", ""))
            if code not in {"404", "NoSuchBucket", "NotFound"}:
                raise
            self.client.create_bucket(Bucket=self.bucket)

    def upload_file(
        self,
        local_path: Path,
        object_path: str,
        content_type: str = "video/mp4",
    ) -> dict[str, int | str | None]:
        self.client.upload_file(
            str(local_path),
            self.bucket,
            object_path,
            ExtraArgs={"ContentType": content_type},
        )
        return self.stat(object_path)

    def upload_json(
        self,
        payload: dict[str, Any],
        object_path: str,
    ) -> dict[str, int | str | None]:
        body = json.dumps(payload, ensure_ascii=False, indent=4).encode("utf-8")
        self.client.put_object(
            Bucket=self.bucket,
            Key=object_path,
            Body=body,
            ContentType="application/json",
        )

        return self.stat(object_path)

    def exists(self, object_path: str) -> bool:
        try:
            info = self.client.head_object(Bucket=self.bucket, Key=object_path)
            return int(info.get("ContentLength") or 0) > 0
        except ClientError as exc:
            code = str(exc.response.get("Error", {}).get("Code", ""))
            if code in {"404", "NoSuchKey", "NoSuchBucket", "NotFound"}:
                return False
            raise

    def stat(self, object_path: str) -> dict[str, int | str | None]:
        info = self.client.head_object(Bucket=self.bucket, Key=object_path)
        return {
            "path": object_path,
            "size_bytes": int(info.get("ContentLength") or 0),
            "mime_type": info.get("ContentType"),
        }
