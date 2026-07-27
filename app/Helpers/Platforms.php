<?php

declare(strict_types=1);

namespace App\Helpers;

final class Platforms
{
    /** @var array<string, string> */
    private const array NAMES = [
        'youtube' => 'YouTube',
        'tiktok' => 'TikTok',
        'tiktok_official' => 'TikTok (API oficial)',
        'instagram' => 'Instagram Reels',
        'facebook' => 'Facebook Reels',
        'kwai' => 'Kwai',
    ];

    /** @var list<string> */
    private const array IMPLEMENTED = ['youtube', 'tiktok'];

    public static function name(string $platform): string
    {
        return self::NAMES[$platform] ?? ucfirst($platform);
    }

    /** @return list<string> */
    public static function implemented(): array
    {
        return self::IMPLEMENTED;
    }
}
