<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\TranscriptionStatusEnum;
use App\Jobs\Concerns\TransfersStorageFiles;
use App\Models\VideoCut;
use App\Services\API\Discord\DiscordNotifierService;
use App\Services\Video\TranscribeService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

final class StartCutTranscribeJob implements ShouldQueue
{
    use Dispatchable;
    use Queueable;
    use TransfersStorageFiles;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(public int $cutId)
    {
        $this->onQueue('processing');
    }

    /**
     * @throws Throwable
     */
    public function handle(TranscribeService $client): void
    {
        $cut = VideoCut::query()->with('video')->find($this->cutId);

        if (! $cut instanceof VideoCut) {
            Log::channel('daily')->warning('[WARN][Cut] Corte não encontrado ao transcrever — ignorando.', ['id' => $this->cutId]);

            return;
        }

        $audioKey = $cut->clipAudioPath();
        throw_if(! Storage::disk('s3')->exists($audioKey), RuntimeException::class, sprintf('Áudio do corte não encontrado no MinIO: "%s".', $audioKey));

        $tmpAudio = $this->pullToTemp($audioKey, 'cut-transcribe-src-');

        try {
            $jobId = $client->createTranscription($tmpAudio, $cut->uuid);

            Log::channel('daily')->info('[INFO][Cut] Transcrição do corte iniciada no transcriber.', [
                'cut_id' => $cut->id,
                'uuid' => $cut->uuid,
                'job_id' => $jobId,
            ]);
        } finally {
            @unlink($tmpAudio);
        }
    }

    public function failed(?Throwable $exception): void
    {
        $error = $exception?->getMessage() ?? 'Falha desconhecida ao iniciar a transcrição do corte.';

        VideoCut::query()->whereKey($this->cutId)
            ->update(['transcription_status' => TranscriptionStatusEnum::Failed]);

        resolve(DiscordNotifierService::class)->error('❌ Transcrição do corte falhou ao iniciar', sprintf('Corte #%d%s%s', $this->cutId, PHP_EOL, $error));
    }
}
