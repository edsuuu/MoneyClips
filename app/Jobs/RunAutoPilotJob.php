<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Video;
use App\Services\VideoProcessor\AutoPilotService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Roda o piloto automático fora do ciclo do webhook (que precisa responder rápido):
 * confirma transcrição, gera cortes e dispara a renderização.
 */
final class RunAutoPilotJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(public readonly int $videoId) {}

    public function handle(AutoPilotService $autoPilot): void
    {
        $video = Video::query()->with('transcript')->find($this->videoId);

        if (! $video instanceof Video) {
            Log::warning('RunAutoPilotJob: vídeo não encontrado.', ['video_id' => $this->videoId]);

            return;
        }

        $autoPilot->run($video);
    }
}
