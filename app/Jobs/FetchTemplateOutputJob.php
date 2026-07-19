<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Jobs\Concerns\TransfersStorageFiles;
use App\Models\ProcessingJob;
use App\Models\YoutubeShort;
use App\Services\Api\Discord\DiscordNotifierService;
use App\Services\AutoCaption\AutoCaptionService;
use App\Services\Processing\TemplateRenderOptionsData;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

final class FetchTemplateOutputJob implements ShouldQueue
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

    public function handle(AutoCaptionService $client, DiscordNotifierService $discord): void
    {
        $job = ProcessingJob::query()->with('youtubeShort')->find($this->processingJobId);
        $short = $job?->youtubeShort;

        if (! $job instanceof ProcessingJob || ! $short instanceof YoutubeShort || $job->remote_id === null) {
            Log::warning('[Processing] Fetch de template sem registro/remote_id — ignorando.', ['id' => $this->processingJobId]);

            return;
        }

        $options = TemplateRenderOptionsData::fromArray($job->options ?? []);
        $tmpOutput = (string) tempnam(sys_get_temp_dir(), 'template-out-');

        try {
            $client->downloadOutputTo($job->remote_id, $options->style, $tmpOutput);

            $outputKey = $this->outputKeyFor($short, $options);
            $this->pushToStorage($tmpOutput, $outputKey);

            $short->forceFill([
                'processed_video_path' => $outputKey,
                'template_rendered_at' => now(),
                'ready_at' => $options->markReady ? ($short->ready_at ?? now()) : $short->ready_at,
            ])->save();

            $job->fill(['status' => 'completed', 'output_path' => $outputKey, 'finished_at' => now()])->save();

            $discord->success(
                '🎨 Template renderizado',
                sprintf('%s%sEstilo %s — disponível em "Com template" para agendar.', $short->title ?? $short->youtube_id, PHP_EOL, $options->style->label()),
            );
        } finally {
            @unlink($tmpOutput);
        }
    }

    public function failed(?Throwable $exception): void
    {
        $error = $exception?->getMessage() ?? 'Falha desconhecida ao baixar o template.';

        ProcessingJob::query()->whereKey($this->processingJobId)
            ->update(['status' => 'failed', 'error' => $error, 'finished_at' => now()]);

        resolve(DiscordNotifierService::class)->error('❌ Falha ao baixar template renderizado', sprintf('Job #%d%s%s', $this->processingJobId, PHP_EOL, $error));
    }

    private function outputKeyFor(YoutubeShort $short, TemplateRenderOptionsData $options): string
    {
        $dir = dirname((string) $short->video_path);
        $dir = $dir === '.' || $dir === '' ? 'shorts' : $dir;

        return sprintf('%s/template_%s_%s.mp4', $dir, $options->style->value, $short->youtube_id);
    }
}
