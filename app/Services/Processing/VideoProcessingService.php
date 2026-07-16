<?php

declare(strict_types=1);

namespace App\Services\Processing;

use App\Jobs\RunReencodeJob;
use App\Jobs\StartTemplateRenderJob;
use App\Models\ProcessingJob;
use App\Models\YoutubeShort;
use RuntimeException;

/**
 * API dos componentes Livewire pro pipeline de processamento (fluxo 1 do
 * estoque): o operador escolhe SÓ reencode ou template (AutoCaption).
 * Cada chamada cria um processing_job e despacha o job na fila `processing`.
 */
final readonly class VideoProcessingService
{
    public function startReencode(YoutubeShort $short, bool $markReady = false): ProcessingJob
    {
        $this->guardNoPendingJob($short);

        $job = ProcessingJob::query()->create([
            'youtube_short_id' => $short->id,
            'type' => ProcessingJob::TYPE_REENCODE,
            'options' => ['mark_ready' => $markReady],
        ]);

        dispatch(new RunReencodeJob($job->id));

        return $job;
    }

    public function startTemplateRender(YoutubeShort $short, TemplateRenderOptions $options): ProcessingJob
    {
        $this->guardNoPendingJob($short);

        $job = ProcessingJob::query()->create([
            'youtube_short_id' => $short->id,
            'type' => ProcessingJob::TYPE_TEMPLATE,
            'options' => $options->toArray(),
        ]);

        dispatch(new StartTemplateRenderJob($job->id));

        return $job;
    }

    private function guardNoPendingJob(YoutubeShort $short): void
    {
        $pending = ProcessingJob::query()
            ->where('youtube_short_id', $short->id)
            ->pending()
            ->exists();

        throw_if($pending, RuntimeException::class, 'Este vídeo já tem um processamento em andamento.');
    }
}
