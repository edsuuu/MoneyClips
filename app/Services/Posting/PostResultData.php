<?php

declare(strict_types=1);

namespace App\Services\Posting;

use App\Enums\PostStatusEnum;

final readonly class PostResultData
{
    /**
     * @param  'public'|'private'|'unlisted'|null  $privacy
     */
    private function __construct(
        public PostStatusEnum $status,
        public ?string $url = null,
        public ?string $privacy = null,
        public ?string $externalId = null,
        public ?string $error = null,
    ) {}

    /**
     * @param  'public'|'private'|'unlisted'  $privacy
     */
    public static function published(?string $url, string $privacy): self
    {
        return new self(PostStatusEnum::Published, url: $url, privacy: $privacy);
    }

    public static function pending(string $externalId): self
    {
        return new self(PostStatusEnum::Posting, externalId: $externalId);
    }

    public static function failed(string $error): self
    {
        return new self(PostStatusEnum::Failed, error: $error);
    }
}
