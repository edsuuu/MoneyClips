<?php

declare(strict_types=1);

namespace App\Services\AutoPost;

use App\Models\ScheduleSlot;
use App\Models\YoutubeShort;
use App\Services\DiscordNotifier;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * Alerta de estoque baixo (substitui o warnIfLowStock do StockReservation):
 * compara o estoque pronto para agendar com os slots vazios dos próximos
 * 7 dias. Dedupe 1×/dia via Cache::add.
 */
final readonly class StockAlert
{
    public function __construct(private DiscordNotifier $discord) {}

    public function warnIfLow(): void
    {
        $today = CarbonImmutable::now(AutoPost::TIMEZONE)->startOfDay();

        $emptySlots = ScheduleSlot::query()
            ->whereNull('youtube_short_id')
            ->whereNull('dispatched_at')
            ->where('is_active', true)
            ->whereBetween('slot_date', [$today->toDateString(), $today->addDays(6)->toDateString()])
            ->count();

        $ready = YoutubeShort::query()->readyToSchedule()->count();

        if ($ready >= $emptySlots) {
            return;
        }

        if (! Cache::add('social:low-stock-warned', true, now()->endOfDay())) {
            return;
        }

        $this->discord->warning(
            '⚠️ Estoque de Shorts baixo',
            sprintf('Há %d slots vazios nos próximos 7 dias e só %d vídeos prontos para agendar.', $emptySlots, $ready).PHP_EOL.
            'Baixe mais com: php artisan youtube:download-shorts "<url-do-canal>" ou revise o estoque em /meus-videos.',
        );
    }
}
