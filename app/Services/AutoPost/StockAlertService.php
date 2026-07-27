<?php

declare(strict_types=1);

namespace App\Services\AutoPost;

use App\Models\ScheduleSlot;
use App\Models\YoutubeShort;
use App\Services\API\Discord\DiscordNotifierService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

final readonly class StockAlertService
{
    public function __construct(private DiscordNotifierService $discord) {}

    public function warnIfLow(): void
    {
        $today = CarbonImmutable::now()->startOfDay();

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
            'Baixe mais em /meus-videos (botão "Novo download") ou revise o estoque.',
        );
    }
}
