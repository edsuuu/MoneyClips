<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\YoutubeShort;
use App\Services\Youtube\YoutubeChannelService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Baixa um conjunto de Shorts de um canal e, ao final, agenda as postagens.
 *
 * Cada vídeo é tratado de forma independente: se um falhar, os demais
 * continuam (erros são tratados silenciosamente, apenas logados).
 *
 * Funcionalidade isolada — não altera nada existente.
 */
final class YoutubeDownloadJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 3600;

    public int $tries = 1;

    /**
     * @param  array<int, array{id: string, url?: string}>  $videos
     */
    public function __construct(public readonly array $videos) {}

    public function handle(YoutubeChannelService $service): void
    {
        $downloadedIds = [];

        foreach ($this->videos as $video) {
            $youtubeId = $video['id'];
            if ($youtubeId === '') {
                continue;
            }

            try {
                $result = $service->downloadShort($youtubeId, $video['url'] ?? null);

                if ($result !== null) {
                    Log::info('[YoutubeDownloadJob] Short baixado.', $result);
                    $downloadedIds[] = $youtubeId;
                }
            } catch (Throwable $e) {
                // Falha silenciosa por vídeo: segue para os demais.
                Log::error('[YoutubeDownloadJob] Falha ao baixar vídeo; continuando.', [
                    'youtube_id' => $youtubeId,
                    'exception' => $e->getMessage(),
                ]);
            }
        }

        if ($downloadedIds === []) {
            return;
        }

        // Agenda as postagens dos vídeos recém-baixados.
        $shorts = YoutubeShort::query()
            ->whereIn('youtube_id', $downloadedIds)
            ->get();

        $service->scheduleShorts($shorts);
    }
}
