<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Video;
use App\Services\Upload\Data\MultipartSessionData;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @property-read Video $resource */
final class UploadSessionResource extends JsonResource
{
    public static $wrap;

    public function __construct(Video $video, private readonly MultipartSessionData $session)
    {
        parent::__construct($video);
    }

    /** @return array<string, string|int> */
    public function toArray(Request $request): array
    {
        return [
            'video_uuid' => $this->resource->uuid,
            ...$this->session->toArray(),
        ];
    }
}
