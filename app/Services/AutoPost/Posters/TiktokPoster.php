<?php

declare(strict_types=1);

namespace App\Services\AutoPost\Posters;

use App\Models\AutoPostSettings;
use App\Models\YoutubeShort;
use App\Services\DiscordNotifier;
use App\Services\TikTok\TiktokPostService;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Enfileira a postagem no TikTok via microserviço uploader (ASSÍNCRONO).
 *
 * O sucesso real é confirmado pelo webhook em /api/tiktok-posts/callback —
 * aqui só registramos que o job foi enfileirado e devolvemos PosterResult::queued.
 */
final readonly class TiktokPoster implements PosterContract
{
    public function __construct(
        private TiktokPostService $tiktok,
        private DiscordNotifier $discord,
    ) {}

    public function platform(): string
    {
        return 'tiktok';
    }

    public function isEnabled(): bool
    {
        return AutoPostSettings::current()->tiktok_enabled;
    }

    public function post(YoutubeShort $short): PosterResult
    {
        try {
            $jobId = $this->tiktok->queuePost(
                $short->youtube_id,
                $short->title ?? $short->youtube_id,
                $short->hashtags ?? [],
                $short->video_path,
            );

            Log::info('[AutoPost][TikTok] Short enfileirado no uploader.', [
                'id' => $short->id,
                'job_id' => $jobId,
            ]);

            return PosterResult::queued($this->platform());
        } catch (Throwable $throwable) {
            Log::error('[AutoPost][TikTok] Falha ao enfileirar.', [
                'id' => $short->id,
                'error' => $throwable->getMessage(),
            ]);
            $this->discord->error(
                '❌ Falha ao enfileirar Short no TikTok',
                ($short->title ?? $short->youtube_id).PHP_EOL.$throwable->getMessage(),
            );

            return PosterResult::failed($this->platform(), $throwable->getMessage());
        }
    }
}
