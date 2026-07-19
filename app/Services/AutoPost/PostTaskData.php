<?php

declare(strict_types=1);

namespace App\Services\AutoPost;

use App\Models\ScheduleSlot;
use App\Models\YoutubeShort;

final readonly class PostTaskData
{
    /**
     * @param  list<string>  $hashtags
     */
    public function __construct(
        public YoutubeShort $short,
        public ?ScheduleSlot $slot,
        public string $title,
        public array $hashtags,
        public string $videoPath,
    ) {}

    public static function fromShort(YoutubeShort $short, ?ScheduleSlot $slot = null): self
    {
        return new self(
            short: $short,
            slot: $slot,
            title: $short->title ?? $short->youtube_id,
            hashtags: array_values($short->hashtags ?? []),
            videoPath: $short->postableVideoPath(),
        );
    }
}
