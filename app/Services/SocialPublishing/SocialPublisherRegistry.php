<?php

declare(strict_types=1);

namespace App\Services\SocialPublishing;

use App\Services\SocialPublishing\Contracts\SocialPublisher;
use App\Services\SocialPublishing\Publishers\FacebookPublisher;
use App\Services\SocialPublishing\Publishers\InstagramPublisher;
use App\Services\SocialPublishing\Publishers\TikTokPublisher;
use App\Services\SocialPublishing\Publishers\YouTubePublisher;

final class SocialPublisherRegistry
{
    /**
     * @var array<string, SocialPublisher>
     */
    private array $publishers;

    public function __construct(
        YouTubePublisher $youtube,
        TikTokPublisher $tiktok,
        InstagramPublisher $instagram,
        FacebookPublisher $facebook,
    ) {
        $this->publishers = [
            $youtube->key() => $youtube,
            $tiktok->key() => $tiktok,
            $instagram->key() => $instagram,
            $facebook->key() => $facebook,
        ];
    }

    /**
     * @return array<string, SocialPublisher>
     */
    public function all(): array
    {
        return $this->publishers;
    }

    public function for(string $key): ?SocialPublisher
    {
        return $this->publishers[$key] ?? null;
    }

    /**
     * Lista [key => label] para a UI.
     *
     * @return array<string, string>
     */
    public function labels(): array
    {
        return array_map(static fn (SocialPublisher $p): string => $p->label(), $this->publishers);
    }
}
