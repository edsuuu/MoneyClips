<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\VideoStatusEnum;
use App\Models\Video;
use App\Services\API\Discord\DiscordNotifierService;
use App\Services\Upload\DownloadYoutubeService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Dispara o download no microserviço e encerra: baixar um vídeo longo leva
 * minutos, e quem fecha o ciclo é o webhook.
 */
final class StartYoutubeDownloadJob implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(public int $videoId, public string $url)
    {
        $this->onQueue('processing');
    }

    /**
     * @throws Throwable
     */
    public function handle(DownloadYoutubeService $service): void
    {
        $video = Video::query()->find($this->videoId);

        if (! $video instanceof Video) {
            Log::warning('[DownloadYoutube] Vídeo inexistente ao iniciar download.', ['id' => $this->videoId]);

            return;
        }

        if ($video->status !== VideoStatusEnum::Downloading) {
            Log::info('[DownloadYoutube] Vídeo fora do estado "downloading" — ignorando.', [
                'id' => $video->id,
                'status' => $video->status->value,
            ]);

            return;
        }

        $service->startDownload($video, $this->url);

        Log::info('[DownloadYoutube] Download enfileirado no microserviço.', ['video_id' => $video->id]);
    }

    public function failed(?Throwable $exception): void
    {
        $error = $exception?->getMessage() ?? 'Falha desconhecida ao iniciar o download.';

        Video::query()->whereKey($this->videoId)->update([
            'status' => VideoStatusEnum::Failed,
            'error' => $error,
        ]);

        resolve(DiscordNotifierService::class)->error(
            '❌ Download do YouTube falhou ao iniciar',
            sprintf('Vídeo #%d%s%s', $this->videoId, PHP_EOL, $error),
        );
    }
}
