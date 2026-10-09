<?php

declare(strict_types=1);

namespace App\Enums;

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

    // ponytail: nenhum provider real ainda — cada PR de provider (YouTube API, TikTokUploader, TikTok oficial) troca isto por um match
    // case → classe. Até lá nada está ligado ao container e a postagem falha com o motivo (só os testes ligam um fake).
    public function service(): PostProviderInterface
    {
        return resolve(PostProviderInterface::class);
    }
}
