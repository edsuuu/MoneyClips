<?php

declare(strict_types=1);

namespace App\Enums;

use App\Services\API\Youtube\YoutubePostService;
use App\Services\Posting\PostProviderInterface;
use InvalidArgumentException;

enum PostProviderEnum: string
{
    case YoutubeApi = 'youtube_api';
    case TiktokUploader = 'tiktok_uploader';

    public static function defaultFor(string $platform): self
    {
        return match ($platform) {
            'youtube' => self::YoutubeApi,
            'tiktok' => self::TiktokUploader,
            default => throw new InvalidArgumentException(sprintf('Plataforma sem provider padrão: "%s".', $platform)),
        };
    }

    // ponytail: o TikTokUploader ainda não tem provider (PR próprio): resolve a interface, que só os testes ligam a um fake.
    public function service(): PostProviderInterface
    {
        return match ($this) {
            self::YoutubeApi => resolve(YoutubePostService::class),
            self::TiktokUploader => resolve(PostProviderInterface::class),
        };
    }
}
