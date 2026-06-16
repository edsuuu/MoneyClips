from typing import Literal

from pydantic import BaseModel, Field, HttpUrl, model_validator


class DownloadRequest(BaseModel):
    channel_url: HttpUrl
    webhook_url: HttpUrl | None = None
    dispatch_on_complete: bool = True

    @model_validator(mode="after")
    def require_webhook_for_auto_dispatch(self) -> "DownloadRequest":
        if self.dispatch_on_complete and self.webhook_url is None:
            raise ValueError("webhook_url is required when dispatch_on_complete is true")
        return self


class AcceptedResponse(BaseModel):
    job_id: str
    status: str = "accepted"


class DispatchRequest(BaseModel):
    mode: Literal["batch", "all"] = "batch"
    batch_size: int = Field(50, ge=1, le=1000)
    webhook_url: HttpUrl | None = None
    delete_after_dispatch: bool = False


class DispatchResponse(BaseModel):
    job_id: str
    sent_items: int
    dispatch_status: str


class BulkDispatchResponse(BaseModel):
    sent_items: int
    dispatch_status: str
    jobs: list[DispatchResponse]


class JobStatusResponse(BaseModel):
    job_id: str
    status: str
    dispatch_status: str
    total: int
    pending: int
    processing: int
    completed: int
    failed: int
    dispatch_pending: int
    last_error: str | None = None


class ItemSummary(BaseModel):
    youtube_id: str
    title: str | None = None
    hashtags: list[str]
    storage_path: str | None = None
    storage_size_bytes: int | None = None
    status: str
    dispatch_status: str


class ItemsResponse(BaseModel):
    total: int
    items: list[ItemSummary]
