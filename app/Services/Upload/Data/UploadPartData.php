<?php

declare(strict_types=1);

namespace App\Services\Upload\Data;

final readonly class UploadPartData
{
    public function __construct(
        public int $partNumber,
        public string $etag,
    ) {}

    /** @return array<string, string|int> */
    public function toArray(): array
    {
        return ['PartNumber' => $this->partNumber, 'ETag' => $this->etag];
    }
}
