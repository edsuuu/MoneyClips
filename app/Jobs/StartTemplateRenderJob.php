<?php

declare(strict_types=1);

namespace App\Jobs;

use App\DataTransferObjects\TemplateRenderOptionsData;
use App\Jobs\Concerns\TransfersStorageFiles;
use App\Models\ProcessingJob;
use App\Models\YoutubeShort;
use App\Services\AutoCaption\AutoCaptionService;
use App\Services\Discord\DiscordNotifierService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Inicia um render de template no AutoCaption: baixa o vídeo do MinIO, sobe
 * por multipart e guarda o uuid remoto. A conclusão chega pelo webhook
 * (/api/autocaption/webhook), que despacha o FetchTemplateOutputJob.
 */
final class StartTemplateRenderJob implements ShouldQueue
{
    use Dispatchable;
    use Queueable;
    use TransfersStorageFiles;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(public int $processingJobId)
    {
        $this->onQueue('processing');
    }

    public function handle(AutoCaptionService $client): void
    {
        $job = ProcessingJob::query()->with('youtubeShort')->find($this->processingJobId);
        $short = $job?->youtubeShort;

        if (! $job instanceof ProcessingJob || ! $short instanceof YoutubeShort) {
            Log::warning('[Processing] Job de template sem registro/vídeo — ignorando.', ['id' => $this->processingJobId]);

            return;
        }

        $job->fill(['status' => 'processing', 'started_at' => now()])->save();
        $options = TemplateRenderOptionsData::fromArray($job->options ?? []);

        $sourceKey = (string) $short->video_path;
        throw_if($sourceKey === '' || ! Storage::disk('s3')->exists($sourceKey), RuntimeException::class, sprintf('Vídeo não encontrado no MinIO: "%s".', $sourceKey));

        $tmpSource = $this->pullToTemp($sourceKey, 'template-src-');

        try {
            $remoteId = $client->createRender(
                $tmpSource,
                $options->style,
                $options->channelName,
                $options->channelHandle,
            );

            $job->fill(['remote_id' => $remoteId])->save();
            Log::info('[Processing] Render de template iniciado no AutoCaption.', [
                'short_id' => $short->id,
                'remote_id' => $remoteId,
                'style' => $options->style->value,
            ]);
        } finally {
            @unlink($tmpSource);
        }
    }

    public function failed(?Throwable $exception): void
    {
        $error = $exception?->getMessage() ?? 'Falha desconhecida ao iniciar o render.';

        ProcessingJob::query()->whereKey($this->processingJobId)
            ->update(['status' => 'failed', 'error' => $error, 'finished_at' => now()]);

        resolve(DiscordNotifierService::class)->error('❌ Render de template falhou ao iniciar', sprintf('Job #%d%s%s', $this->processingJobId, PHP_EOL, $error));
    }
}
