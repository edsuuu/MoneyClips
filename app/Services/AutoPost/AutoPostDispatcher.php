<?php

declare(strict_types=1);

namespace App\Services\AutoPost;

use App\Jobs\PostSlotToPlatform;
use App\Models\ScheduleSlot;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

/**
 * Orquestrador da agenda em banco: a cada tick do scheduler, encontra os
 * slots devidos (com tolerância de GRACE_MINUTES pra sobreviver a tick
 * perdido do cron), reivindica cada um atomicamente e enfileira 1 job por
 * plataforma habilitada. O tick nunca posta nada — quem posta é a fila
 * `posting` (YouTube leva minutos, TikTok/Playwright até 15).
 *
 * O claim atômico (UPDATE ... WHERE dispatched_at IS NULL) substitui o
 * antigo lock por Cache::add: repostagem dupla é impossível mesmo com
 * overlap de scheduler + clique manual em "Forçar agora".
 */
final readonly class AutoPostDispatcher
{
    /** Tolerância: slot ainda dispara até N minutos depois do horário. */
    public const int GRACE_MINUTES = 5;

    public function __construct(private PosterRegistry $posters) {}

    public function dispatchDueSlots(): void
    {
        $now = CarbonImmutable::now(AutoPost::TIMEZONE);

        $due = ScheduleSlot::query()
            ->due($now, self::GRACE_MINUTES)
            ->orderBy('slot_time')
            ->get();

        foreach ($due as $slot) {
            $this->dispatchSlot($slot);
        }
    }

    /**
     * Reivindica o slot e enfileira os posts. Retorna false quando outro
     * tick/clique já reivindicou (ou o slot não tem vídeo).
     */
    public function dispatchSlot(ScheduleSlot $slot): bool
    {
        $enabled = $this->posters->enabled();
        if ($enabled === []) {
            Log::warning('[AutoPost] Nenhuma plataforma habilitada — slot não despachado.', ['slot_id' => $slot->id]);

            return false;
        }

        $claimed = ScheduleSlot::query()
            ->whereKey($slot->id)
            ->whereNull('dispatched_at')
            ->whereNotNull('youtube_short_id')
            ->update(['dispatched_at' => now()]);

        if ($claimed !== 1) {
            Log::info('[AutoPost] Slot já reivindicado ou sem vídeo — ignorando.', ['slot_id' => $slot->id]);

            return false;
        }

        Log::info('[AutoPost] Slot despachado.', [
            'slot_id' => $slot->id,
            'platforms' => array_map(static fn ($p): string => $p->platform(), $enabled),
        ]);

        foreach ($enabled as $poster) {
            dispatch(new PostSlotToPlatform($slot->id, $poster->platform()));
        }

        return true;
    }
}
