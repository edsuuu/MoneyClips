<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\ScheduleSlot;
use App\Models\YoutubeShort;
use App\Services\Api\Discord\DiscordNotifierService;
use App\Services\AutoPost\AutoPostDispatcherService;
use App\Services\Reencode\ReencodeShortService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

final class ReencodeAndPostSlotJob implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public int $tries = 1;

    public int $timeout = 1200;

    public function __construct(public int $slotId)
    {
        $this->onQueue('posting');
    }

    public function handle(ReencodeShortService $reencoder, AutoPostDispatcherService $dispatcher): void
    {
        $slot = ScheduleSlot::query()->with('youtubeShort')->find($this->slotId);
        $short = $slot?->youtubeShort;

        if (! $slot instanceof ScheduleSlot || ! $short instanceof YoutubeShort) {
            Log::warning('[AutoPost][Random] Slot/vídeo sumiu antes do fluxo aleatório — ignorando.', ['slot_id' => $this->slotId]);

            return;
        }

        if ($short->processed_video_path === null) {
            try {
                $reencoder->reencode($short);
            } catch (Throwable $throwable) {
                // Reencode é melhoria de qualidade, não pré-condição: falhou,
                // posta o original mesmo (postableVideoPath cai no video_path).
                Log::error('[AutoPost][Random] Reencode falhou — postando o vídeo original.', ['short_id' => $short->id, 'error' => $throwable->getMessage()]);
                resolve(DiscordNotifierService::class)->warning(
                    '⚠️ Modo aleatório: reencode falhou',
                    ($short->title ?? $short->youtube_id).PHP_EOL.'Postando o vídeo original sem reencode. Erro: '.$throwable->getMessage(),
                );
            }
        }

        $dispatcher->fanOut($slot);
    }

    public function failed(?Throwable $exception): void
    {
        $error = $exception?->getMessage() ?? 'Falha desconhecida no fluxo aleatório.';

        Log::error('[AutoPost][Random] Fluxo aleatório falhou.', ['slot_id' => $this->slotId, 'error' => $error]);

        resolve(DiscordNotifierService::class)->error(
            '❌ Modo aleatório falhou',
            sprintf('Slot #%d%s%s', $this->slotId, PHP_EOL, $error),
        );
    }
}
