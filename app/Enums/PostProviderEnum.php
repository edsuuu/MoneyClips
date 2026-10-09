<?php

declare(strict_types=1);

namespace App\Enums;

use App\Services\API\TikTok\TikTokPostService;
use App\Services\API\Youtube\YoutubePostService;
use App\Services\Posting\PostProviderInterface;
use App\Services\TikTokUploader\TikTokUploaderPostService;
use InvalidArgumentException;

enum PostProviderEnum: string
{
    case YoutubeApi = 'youtube_api';
    case TiktokUploader = 'tiktok_uploader';
    case TiktokOfficial = 'tiktok_official';

    public static function defaultFor(string $platform): self
    {
        return match ($platform) {
            'youtube' => self::YoutubeApi,
            'tiktok' => self::TiktokUploader,
            default => throw new InvalidArgumentException(sprintf('Plataforma sem provider padrão: "%s".', $platform)),
        };
    }

    public function service(): PostProviderInterface
    {
        return match ($this) {
            self::YoutubeApi => resolve(YoutubePostService::class),
            self::TiktokUploader => resolve(TikTokUploaderPostService::class),
            self::TiktokOfficial => resolve(TikTokPostService::class),
        };
    }
}
