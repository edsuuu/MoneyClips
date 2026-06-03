<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Video;
use App\Services\VideoProcessor\VideoProcessorService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

final class ProcessVideoJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(
        public readonly Video $video,
    ) {}

    public function handle(VideoProcessorService $videoProcessor): void
    {
        $log = Log::channel('daily');
        $ctx = ['video_id' => $this->video->id, 'url' => $this->video->url];

        $log->info('[ProcessVideoJob] Enviando para API de processamento.', $ctx);

        try {
            $job = $videoProcessor->startIngest($this->video);

            $jobId = $job->external_job_id;
            if (is_string($jobId) && $jobId !== '') {
                $this->video->update(['current_job_id' => $jobId]);
                $log->info('[ProcessVideoJob] Job de ingestão criado.', $ctx + ['job_id' => $jobId]);
            }
        } catch (Throwable $throwable) {
            $log->error('[ProcessVideoJob] Falha ao enviar para API.', $ctx + ['exception' => $throwable]);
            $this->fail($throwable);
        }
    }
}
