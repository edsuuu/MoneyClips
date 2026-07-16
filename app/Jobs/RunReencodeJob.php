<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Jobs\Concerns\TransfersStorageFiles;
use App\Models\ProcessingJob;
use App\Models\YoutubeShort;
use App\Services\Api\Discord\DiscordNotifierService;
use App\Services\Reencode\ReencodeService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Reencode síncrono via microserviço (fila `processing`): baixa o vídeo do
 * MinIO, envia por multipart, grava o resultado `_HQ` de volta no MinIO e
 * aponta processed_video_path pra ele. Só o Laravel toca o S3.
 */
final class RunReencodeJob implements ShouldQueue
{
    use Dispatchable;
    use Queueable;
    use TransfersStorageFiles;

    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(public int $processingJobId)
    {
        $this->onQueue('processing');
    }

    public function handle(ReencodeService $client): void
    {
        $job = ProcessingJob::query()->with('youtubeShort')->find($this->processingJobId);
        $short = $job?->youtubeShort;

        if (! $job instanceof ProcessingJob || ! $short instanceof YoutubeShort) {
            Log::warning('[Processing] Job de reencode sem registro/vídeo — ignorando.', ['id' => $this->processingJobId]);

            return;
        }

        $job->fill(['status' => 'processing', 'started_at' => now()])->save();

        $sourceKey = (string) $short->video_path;
        throw_if($sourceKey === '' || ! Storage::disk('s3')->exists($sourceKey), RuntimeException::class, sprintf('Vídeo não encontrado no MinIO: "%s".', $sourceKey));

        $tmpSource = $this->pullToTemp($sourceKey, 'reencode-src-');
        $tmpOutput = (string) tempnam(sys_get_temp_dir(), 'reencode-out-');

        try {
            $reencoded = $client->reencode($tmpSource, $short->youtube_id, $tmpOutput);

            if ($reencoded) {
                $outputKey = $this->outputKeyFor($sourceKey);
                $this->pushToStorage($tmpOutput, $outputKey);

                $short->forceFill(['processed_video_path' => $outputKey])->save();
                $job->output_path = $outputKey;
            }

            if ((bool) ($job->options['mark_ready'] ?? false) && $short->ready_at === null) {
                $short->forceFill(['ready_at' => now()])->save();
            }

            $job->fill(['status' => 'completed', 'finished_at' => now()])->save();
            Log::info('[Processing] Reencode concluído.', ['short_id' => $short->id, 'reencoded' => $reencoded]);
        } finally {
            @unlink($tmpSource);
            @unlink($tmpOutput);
        }
    }

    public function failed(?Throwable $exception): void
    {
        $error = $exception?->getMessage() ?? 'Falha desconhecida no reencode.';

        ProcessingJob::query()->whereKey($this->processingJobId)
            ->update(['status' => 'failed', 'error' => $error, 'finished_at' => now()]);

        resolve(DiscordNotifierService::class)->error('❌ Reencode falhou', sprintf('Job #%d%s%s', $this->processingJobId, PHP_EOL, $error));
    }

    /** "shorts/x/short_x.mp4" → "shorts/x/short_x_HQ.mp4". */
    private function outputKeyFor(string $sourceKey): string
    {
        $replaced = preg_replace('/\.(\w+)$/', '_HQ.$1', $sourceKey);

        return is_string($replaced) && $replaced !== $sourceKey ? $replaced : $sourceKey.'_HQ.mp4';
    }
}
