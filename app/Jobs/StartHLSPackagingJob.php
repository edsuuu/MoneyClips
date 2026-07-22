<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Video;
use App\Services\Api\Discord\DiscordNotifierService;
use App\Services\HLS\HLSPackagerService;
use App\Services\HLS\VideoStatusEnum;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Dispara o empacotamento e encerra: o ffmpeg de um vídeo longo leva horas, e
 * quem fecha o ciclo é o webhook.
 */
final class StartHLSPackagingJob implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(public int $videoId)
    {
        $this->onQueue('processing');
    }

    public function handle(HLSPackagerService $packager): void
    {
        $video = Video::query()->find($this->videoId);

        if (! $video instanceof Video) {
            Log::warning('[HLS] Vídeo inexistente ao iniciar empacotamento.', ['id' => $this->videoId]);

            return;
        }

        if ($video->status !== VideoStatusEnum::Uploaded) {
            Log::info('[HLS] Vídeo fora do estado "uploaded" — ignorando.', [
                'id' => $video->id,
                'status' => $video->status->value,
            ]);

            return;
        }

        $sourceKey = $video->originalPath();
        throw_unless(
            Storage::disk('s3')->exists($sourceKey),
            RuntimeException::class,
            sprintf('Vídeo não encontrado no MinIO: "%s".', $sourceKey),
        );

        $packager->startPackaging($video);

        $video->fill([
            'status' => VideoStatusEnum::Packaging,
            'progress' => 0,
        ])->save();

        Log::info('[HLS] Empacotamento iniciado.', ['video_id' => $video->id]);
    }

    public function failed(?Throwable $exception): void
    {
        $error = $exception?->getMessage() ?? 'Falha desconhecida ao iniciar o empacotamento.';

        Video::query()->whereKey($this->videoId)->update([
            'status' => VideoStatusEnum::Failed,
            'error' => $error,
        ]);

        resolve(DiscordNotifierService::class)->error(
            '❌ Empacotamento HLS falhou ao iniciar',
            sprintf('Vídeo #%d%s%s', $this->videoId, PHP_EOL, $error),
        );
    }
}
