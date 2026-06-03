<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Status;
use App\Models\Video;
use App\Services\VideoProcessor\Downloader;
use App\Services\VideoProcessor\Uploader;
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

    public int $timeout = 3600;

    public function __construct(
        public readonly Video $video,
    ) {}

    public function handle(): void
    {
        $log = Log::channel('daily');
        $ctx = ['video_id' => $this->video->id, 'url' => $this->video->url];

        $log->info('[ProcessVideoJob] Iniciando.', $ctx);
        $this->video->update(['status_id' => Status::idFor('downloading')]);

        try {
            $downloader = new Downloader();
            $uploader = new Uploader();

            // ── vídeo ─────────────────────────────────────────────────────────
            $log->info('[ProcessVideoJob] Baixando vídeo.', $ctx);
            $this->video->update(['download_stage' => 'video', 'progress' => 0]);

            $videoResult = $downloader->downloadVideo(
                url: $this->video->url,
                onProgress: fn (float $pct) => $this->video->update(['progress' => (int) $pct]),
            );

            $log->info('[ProcessVideoJob] Vídeo baixado.', $ctx + [
                'title' => $videoResult['title'],
                'duration' => $videoResult['duration'],
                'file_path' => $videoResult['file_path'],
            ]);

            $file = $uploader->upload($this->video, $videoResult['file_path'], 'original');
            $log->info('[ProcessVideoJob] Vídeo enviado ao S3.', $ctx + ['remote_path' => $file->path]);

            // ── áudio ─────────────────────────────────────────────────────────
            $log->info('[ProcessVideoJob] Baixando áudio.', $ctx);
            $this->video->update(['download_stage' => 'audio', 'progress' => 0]);

            $audioResult = $downloader->downloadAudio(
                url: $this->video->url,
                onProgress: fn (float $pct) => $this->video->update(['progress' => (int) $pct]),
            );

            $log->info('[ProcessVideoJob] Áudio baixado.', $ctx + ['file_path' => $audioResult['file_path']]);

            $audioFile = $uploader->upload($this->video, $audioResult['file_path'], 'audio');
            $log->info('[ProcessVideoJob] Áudio enviado ao S3.', $ctx + ['remote_path' => $audioFile->path]);

            // ── finaliza ──────────────────────────────────────────────────────
            $this->video->update([
                'status_id' => Status::idFor('processing'),
                'download_stage' => null,
                'progress' => 100,
                'title' => $this->video->title ?: $videoResult['title'],
                'duration_seconds' => $videoResult['duration'],
            ]);

            $log->info('[ProcessVideoJob] Concluído com sucesso.', $ctx);
        } catch (Throwable $throwable) {
            $log->error('[ProcessVideoJob] Falha.', $ctx + ['exception' => $throwable]);
            $this->video->update(['status_id' => Status::idFor('failed')]);
            $this->fail($throwable);
        }
    }
}
