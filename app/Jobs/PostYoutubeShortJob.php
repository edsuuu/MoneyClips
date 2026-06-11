<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\YoutubeShort;
use App\Services\DiscordNotifier;
use App\Services\Youtube\ShortsPoster;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Posta um único Short no YouTube. É enfileirado pelo comando
 * youtube:dispatch-posts (um job por Short sorteado) ou pela página /shorts.
 */
final class PostYoutubeShortJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $backoff = 60;

    public int $timeout = 900;

    public function __construct(public readonly int $youtubeShortId) {}

    public function handle(ShortsPoster $poster, DiscordNotifier $discord): void
    {
        $short = YoutubeShort::query()->find($this->youtubeShortId);

        if ($short === null) {
            Log::warning('[PostYoutubeShortJob] Short não encontrado.', ['id' => $this->youtubeShortId]);

            return;
        }

        if ($short->posted_at !== null) {
            Log::info('[PostYoutubeShortJob] Short já postado, ignorando.', ['id' => $short->id]);

            return;
        }

        $videoId = $poster->post($short);
        $link = 'https://www.youtube.com/shorts/'.$videoId;

        Log::info('[PostYoutubeShortJob] Short postado.', [
            'id' => $short->id,
            'youtube_video_id' => $videoId,
            'link' => $link,
        ]);

        $discord->success(
            '✅ Short postado no YouTube',
            ($short->title ?? $short->youtube_id).(PHP_EOL.$link),
            $link,
        );
    }

    public function failed(Throwable $e): void
    {
        Log::error('[PostYoutubeShortJob] Falha ao postar Short.', [
            'id' => $this->youtubeShortId,
            'error' => $e->getMessage(),
        ]);

        resolve(DiscordNotifier::class)->error(
            '❌ Falha ao postar Short no YouTube',
            "Short ID: {$this->youtubeShortId}\nErro: {$e->getMessage()}",
        );
    }
}
