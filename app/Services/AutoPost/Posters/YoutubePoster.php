<?php

declare(strict_types=1);

namespace App\Services\AutoPost\Posters;

use App\Models\YoutubeShort;
use App\Services\DiscordNotifier;
use App\Services\Youtube\ShortsPoster;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Posta um Short no YouTube de forma SÍNCRONA via YouTube Data API.
 *
 * O ShortsPoster já marca posted_youtube_at / youtube_video_id no sucesso —
 * aqui só logamos, notificamos Discord e devolvemos PosterResult.
 */
final readonly class YoutubePoster implements PosterContract
{
    public function __construct(
        private ShortsPoster $poster,
        private DiscordNotifier $discord,
    ) {}

    public function platform(): string
    {
        return 'youtube';
    }

    public function isEnabled(): bool
    {
        return (bool) config('services.youtube_shorts.posting.youtube_enabled', true);
    }

    public function post(YoutubeShort $short): PosterResult
    {
        try {
            $videoId = $this->poster->post($short);
            $link = 'https://www.youtube.com/shorts/'.$videoId;

            Log::info('[AutoPost][YouTube] Short postado.', ['id' => $short->id, 'link' => $link]);
            $this->discord->success(
                '✅ Short postado no YouTube',
                ($short->title ?? $short->youtube_id).PHP_EOL.$link,
                $link,
            );

            return PosterResult::ok($this->platform(), $link);
        } catch (Throwable $throwable) {
            Log::error('[AutoPost][YouTube] Falha ao postar.', [
                'id' => $short->id,
                'error' => $throwable->getMessage(),
            ]);
            $this->discord->error(
                '❌ Falha ao postar Short no YouTube',
                'Short ID: '.$short->id.PHP_EOL.'Erro: '.$throwable->getMessage(),
            );

            return PosterResult::failed($this->platform(), $throwable->getMessage());
        }
    }
}
