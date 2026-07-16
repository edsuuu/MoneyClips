<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\ProcessingJob;
use App\Models\YoutubeShort;
use App\Services\Api\Discord\DiscordNotifierService;
use App\Services\Reencode\ReencodeShortService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Reencode síncrono via microserviço (fila `processing`): delega ao
 * ReencodeShortService (MinIO → multipart → MinIO → processed_video_path)
 * e cuida do ciclo de vida do processing_job.
 */
final class RunReencodeJob implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(public int $processingJobId)
    {
        $this->onQueue('processing');
    }

    public function handle(ReencodeShortService $reencoder): void
    {
        $job = ProcessingJob::query()->with('youtubeShort')->find($this->processingJobId);
        $short = $job?->youtubeShort;

        if (! $job instanceof ProcessingJob || ! $short instanceof YoutubeShort) {
            Log::warning('[Processing] Job de reencode sem registro/vídeo — ignorando.', ['id' => $this->processingJobId]);

            return;
        }

        $job->fill(['status' => 'processing', 'started_at' => now()])->save();

        $reencoded = $reencoder->reencode($short);

        if ($reencoded) {
            $job->output_path = $short->processed_video_path;
        }

        if ((bool) ($job->options['mark_ready'] ?? false) && $short->ready_at === null) {
            $short->forceFill(['ready_at' => now()])->save();
        }

        $job->fill(['status' => 'completed', 'finished_at' => now()])->save();
        Log::info('[Processing] Reencode concluído.', ['short_id' => $short->id, 'reencoded' => $reencoded]);
    }

    public function failed(?Throwable $exception): void
    {
        $error = $exception?->getMessage() ?? 'Falha desconhecida no reencode.';

        ProcessingJob::query()->whereKey($this->processingJobId)
            ->update(['status' => 'failed', 'error' => $error, 'finished_at' => now()]);

        resolve(DiscordNotifierService::class)->error('❌ Reencode falhou', sprintf('Job #%d%s%s', $this->processingJobId, PHP_EOL, $error));
    }
}
