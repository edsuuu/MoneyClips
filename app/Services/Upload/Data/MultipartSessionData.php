<?php

declare(strict_types=1);

namespace App\Services\Upload\Data;

final readonly class MultipartSessionData
{
    public function __construct(
        public string $uploadId,
        public int $partSize,
        public int $partCount,
    ) {}

    /** @return array<string, string|int> */
    public function toArray(): array
    {
        return [
            'upload_id' => $this->uploadId,
            'part_size' => $this->partSize,
            'part_count' => $this->partCount,
        ];
    }
}
