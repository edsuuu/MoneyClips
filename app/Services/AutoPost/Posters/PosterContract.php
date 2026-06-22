<?php

declare(strict_types=1);

namespace App\Services\AutoPost\Posters;

use App\Models\YoutubeShort;

/**
 * Contrato de um poster por plataforma. Cada implementação encapsula a
 * comunicação com a plataforma (YouTube Data API, TikTok uploader, etc.) e
 * devolve um PosterResult uniforme pro orquestrador (AutoPostDispatcher).
 */
interface PosterContract
{
    /** Identificador da plataforma (ex.: 'youtube', 'tiktok'). */
    public function platform(): string;

    /** Lê a flag config services.youtube_shorts.posting.<platform>_enabled. */
    public function isEnabled(): bool;

    public function post(YoutubeShort $short): PosterResult;
}
