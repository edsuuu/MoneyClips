<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\VideoCutStatusEnum;
use App\Models\VideoCut;
use App\Services\API\Discord\DiscordNotifierService;
use App\Services\Video\CutRenderService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Dispara a geração do corte e encerra: o ffmpeg roda no microserviço e quem
 * fecha o ciclo é o webhook /api/webhook/cut.
 */
final class StartCutRenderJob implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(public int $cutId)
    {
        $this->onQueue('processing');
    }

    /**
     * @throws Throwable
     */
    public function handle(CutRenderService $service): void
    {
        $cut = VideoCut::query()->with('video')->find($this->cutId);

        if (! $cut instanceof VideoCut) {
            Log::warning('[Cut] Corte inexistente ao iniciar geração.', ['id' => $this->cutId]);

            return;
        }

        if ($cut->status !== VideoCutStatusEnum::Generating) {
            Log::info('[Cut] Corte fora do estado "generating" — ignorando.', [
                'id' => $cut->id,
                'status' => $cut->status->value,
            ]);

            return;
        }

        $sourceKey = $cut->video->originalPath();
        throw_unless(
            Storage::disk('s3')->exists($sourceKey),
            RuntimeException::class,
            sprintf('Vídeo original não encontrado no s3: "%s".', $sourceKey),
        );

        $service->startRender($cut);

        Log::info('[Cut] Geração do corte iniciada.', ['cut_id' => $cut->id, 'uuid' => $cut->uuid]);
    }

    public function failed(?Throwable $exception): void
    {
        $error = $exception?->getMessage() ?? 'Falha desconhecida ao iniciar a geração do corte.';

        // Guard simétrico ao claim do webhook: um "done" que chegou durante os
        // retries não pode ser sobrescrito por failed.
        $claimed = VideoCut::query()
            ->whereKey($this->cutId)
            ->where('status', VideoCutStatusEnum::Generating->value)
            ->update([
                'status' => VideoCutStatusEnum::Failed,
                'error' => $error,
            ]);

        if ($claimed !== 1) {
            return;
        }

        resolve(DiscordNotifierService::class)->error(
            '❌ Geração de corte falhou ao iniciar',
            sprintf('Corte #%d%s%s', $this->cutId, PHP_EOL, $error),
        );
    }
}
