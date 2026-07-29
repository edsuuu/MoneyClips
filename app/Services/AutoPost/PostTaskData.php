<?php

declare(strict_types=1);

namespace App\Services\AutoPost;

use App\Models\YoutubeShort;

final readonly class PostTaskData
{
    /**
     * @param  list<string>  $hashtags
     */
    public function __construct(
        public YoutubeShort $short,
        public string $title,
        public array $hashtags,
        public string $videoPath,
    ) {}

    public static function fromShort(YoutubeShort $short): self
    {
        return new self(
            short: $short,
            title: $short->title ?? $short->youtube_id,
            hashtags: array_values($short->hashtags ?? []),
            videoPath: $short->postableVideoPath(),
        );
    }
}
