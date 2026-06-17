from pydantic import BaseModel, HttpUrl


class DownloadRequest(BaseModel):
    channel_url: HttpUrl
    webhook_url: HttpUrl


class AcceptedResponse(BaseModel):
    status: str = "started"
    count: int
    channel_url: str
