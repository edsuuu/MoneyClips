<?php

declare(strict_types=1);

namespace App\Services\AutoPost;

use App\Models\YoutubeShort;
use App\Services\DiscordNotifier;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

/**
 * Reserva atômica de Shorts e alerta de estoque baixo.
 *
 * Sorteia candidatos com arquivo no storage, pula "fantasmas" (linha com
 * video_path mas sem objeto na MinIO/S3) e reserva via update condicional em
 * dispatched_at — duas execuções simultâneas nunca pegam o mesmo registro.
 */
final readonly class StockReservation
{
    /** Quantos candidatos sortear por busca ao procurar um com arquivo no storage. */
    private const int PROBE_LIMIT = 50;

    public function __construct(private DiscordNotifier $discord) {}

    /**
     * Sorteia e RESERVA o próximo Short disponível cujo arquivo existe no
     * storage. A reserva é atômica via update condicional em dispatched_at.
     */
    public function reserveNext(): ?YoutubeShort
    {
        $disk = Storage::disk();

        $candidates = YoutubeShort::query()
            ->availableToPost()
            ->inRandomOrder()
            ->limit(self::PROBE_LIMIT)
            ->get();

        foreach ($candidates as $short) {
            $path = (string) $short->video_path;
            if ($path === '') {
                continue;
            }

            if (! $disk->exists($path)) {
                continue;
            }

            $reserved = YoutubeShort::query()
                ->whereKey($short->id)
                ->whereNull('dispatched_at')
                ->update(['dispatched_at' => now()]);

            if ($reserved === 1) {
                return $short->refresh();
            }
        }

        return null;
    }

    /** Avisa no Discord 1x/dia quando o estoque disponível cai até o limiar. */
    public function warnIfLowStock(): void
    {
        $total = YoutubeShort::query()->whereNotNull('video_path')->count();
        if ($total === 0) {
            return;
        }

        $remaining = YoutubeShort::query()->availableToPost()->count();
        $threshold = (float) config('services.youtube_shorts.posting.low_stock_threshold', 0.20);

        if ($remaining / $total > $threshold) {
            return;
        }

        if (! Cache::add('social:low-stock-warned', true, now()->endOfDay())) {
            return;
        }

        $percent = (int) round(($remaining / $total) * 100);
        $this->discord->warning(
            '⚠️ Estoque de Shorts baixo',
            sprintf('Restam %s de %d Shorts (%d%%) para postar.', $remaining, $total, $percent).PHP_EOL.
            'Baixe mais com: php artisan youtube:download-shorts "<url-do-canal>"',
        );
    }
}
