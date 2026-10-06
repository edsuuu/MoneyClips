<?php

declare(strict_types=1);

namespace App\Enums;

enum StockAssetKindEnum: string
{
    case Sfx = 'sfx';
    case Emoji = 'emoji';
    case Image = 'image';
    case MemeSticker = 'meme_sticker';
    case MemeClip = 'meme_clip';

    /** @return list<string> */
    public function allowedExtensions(): array
    {
        return match ($this) {
            self::Sfx => ['mp3', 'wav', 'ogg'],
            self::Emoji, self::Image, self::MemeSticker => ['png', 'webp'],
            self::MemeClip => ['mp4'],
        };
    }

    public function isMeme(): bool
    {
        return match ($this) {
            self::MemeSticker, self::MemeClip => true,
            self::Sfx, self::Emoji, self::Image => false,
        };
    }
}
