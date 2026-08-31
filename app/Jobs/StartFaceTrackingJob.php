<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\TranscriptionStatusEnum;
use App\Jobs\Concerns\TransfersStorageFiles;
use App\Models\VideoCutEdit;
use App\Services\API\Discord\DiscordNotifierService;
use App\Services\Video\FaceTrackingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Dispara o face tracking do clip e encerra: o MediaPipe roda no serviço media
 * e quem fecha o ciclo é o webhook /api/webhook/face-tracking, que grava os
 * keyframes na própria edição.
 */
final class StartFaceTrackingJob implements ShouldQueue
{
    use Dispatchable;
    use Queueable;
    use TransfersStorageFiles;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(public int $editId)
    {
        $this->onQueue('processing');
    }

    /**
     * @throws Throwable
     */
    public function handle(FaceTrackingService $service): void
    {
        $edit = VideoCutEdit::query()->with('videoCut.video')->find($this->editId);

        if (! $edit instanceof VideoCutEdit) {
            Log::channel('daily')->warning('[WARN][FaceTracking] Edição inexistente ao iniciar tracking.', ['id' => $this->editId]);

            return;
        }

        if ($edit->tracking_status !== TranscriptionStatusEnum::Processing) {
            Log::channel('daily')->info('[INFO][FaceTracking] Edição fora do estado "processing" — ignorando.', [
                'id' => $edit->id,
                'tracking_status' => $edit->tracking_status?->value,
            ]);

            return;
        }

        $sourceKey = $edit->videoCut?->clipPath();

        throw_unless(
            $sourceKey !== null && Storage::disk('s3')->exists($sourceKey),
            RuntimeException::class,
            sprintf('Clip fonte da edição não encontrado no s3: "%s".', $sourceKey ?? 'sem corte'),
        );

        $tmpClip = $this->pullToTemp($sourceKey, 'face-tracking-src-');

        try {
            $jobId = $service->createTracking($tmpClip, $edit);

            Log::channel('daily')->info('[INFO][FaceTracking] Tracking iniciado no media.', [
                'edit_id' => $edit->id,
                'uuid' => $edit->uuid,
                'job_id' => $jobId,
            ]);
        } finally {
            @unlink($tmpClip);
        }
    }

    public function failed(?Throwable $exception): void
    {
        $error = $exception?->getMessage() ?? 'Falha desconhecida ao iniciar o face tracking.';

        // Guard simétrico ao claim do webhook: se o "done" chegou antes da
        // falha ser registrada, não sobrescreve keyframes já entregues.
        $claimed = VideoCutEdit::query()
            ->whereKey($this->editId)
            ->where('tracking_status', TranscriptionStatusEnum::Processing->value)
            ->update([
                'tracking_status' => TranscriptionStatusEnum::Failed,
                'tracking_error' => $error,
            ]);

        if ($claimed !== 1) {
            return;
        }

        resolve(DiscordNotifierService::class)->error(
            '❌ Face tracking falhou ao iniciar',
            sprintf('Edição #%d%s%s', $this->editId, PHP_EOL, $error),
        );
    }
}
