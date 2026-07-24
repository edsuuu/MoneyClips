<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\TranscriptionStatusEnum;
use App\Jobs\Concerns\TransfersStorageFiles;
use App\Models\Video;
use App\Services\API\Discord\DiscordNotifierService;
use App\Services\Transcribe\TranscribeService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

final class StartTranscribeJob implements ShouldQueue
{
    use Dispatchable;
    use Queueable;
    use TransfersStorageFiles;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(public int $videoId)
    {
        $this->onQueue('processing');
    }

    /**
     * @throws Throwable
     */
    public function handle(TranscribeService $client): void
    {
        $video = Video::query()->find($this->videoId);

        if (! $video instanceof Video) {
            Log::warning('[Transcribe] Vídeo não encontrado — ignorando.', ['id' => $this->videoId]);

            return;
        }

        $audioKey = $video->audioPath();
        throw_if(! Storage::disk('s3')->exists($audioKey), RuntimeException::class, sprintf('Áudio não encontrado no MinIO: "%s".', $audioKey));

        $tmpAudio = $this->pullToTemp($audioKey, 'transcribe-src-');

        try {
            $jobId = $client->createTranscription($tmpAudio, $video->uuid);

            Log::info('[Transcribe] Transcrição iniciada no transcriber.', [
                'video_id' => $video->id,
                'uuid' => $video->uuid,
                'job_id' => $jobId,
            ]);
        } finally {
            @unlink($tmpAudio);
        }
    }

    public function failed(?Throwable $exception): void
    {
        $error = $exception?->getMessage() ?? 'Falha desconhecida ao iniciar a transcrição.';

        Video::query()->whereKey($this->videoId)
            ->update(['transcription_status' => TranscriptionStatusEnum::Failed]);

        resolve(DiscordNotifierService::class)->error('❌ Transcrição falhou ao iniciar', sprintf('Vídeo #%d%s%s', $this->videoId, PHP_EOL, $error));
    }
}
