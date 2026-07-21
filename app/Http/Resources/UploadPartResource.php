<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Services\Upload\UploadPartData;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @property-read UploadPartData $resource */
final class UploadPartResource extends JsonResource
{
    public static $wrap = 'parts';

    /** @return array<string, string|int> */
    public function toArray(Request $request): array
    {
        return [
            'part_number' => $this->resource->partNumber,
            'etag' => $this->resource->etag,
        ];
    }
}
