<?php

declare(strict_types=1);

namespace App\Services\Upload\Data;

final readonly class YoutubeVideoMetadataData
{
    public function __construct(
        public string $youtubeId,
        public string $title,
        public ?int $durationSeconds,
        public ?int $width,
        public ?int $height,
        public ?string $thumbnailUrl,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromResponse(array $data): self
    {
        $thumbnail = $data['thumbnail'] ?? null;

        return new self(
            youtubeId: (string) ($data['youtube_id'] ?? ''),
            title: (string) ($data['title'] ?? ''),
            durationSeconds: is_numeric($data['duration_seconds'] ?? null) ? (int) $data['duration_seconds'] : null,
            width: is_numeric($data['width'] ?? null) ? (int) $data['width'] : null,
            height: is_numeric($data['height'] ?? null) ? (int) $data['height'] : null,
            thumbnailUrl: is_string($thumbnail) && $thumbnail !== '' ? $thumbnail : null,
        );
    }
}
