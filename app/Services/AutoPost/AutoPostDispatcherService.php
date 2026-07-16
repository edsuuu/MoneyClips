<?php

declare(strict_types=1);

namespace App\Services\AutoPost;

use App\Jobs\PostSlotToPlatformJob;
use App\Jobs\ReencodeAndPostSlotJob;
use App\Models\AppSetting;
use App\Models\ScheduleSlot;
use App\Models\SocialPost;
use App\Models\YoutubeShort;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
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
final readonly class AutoPostDispatcherService
{
    /** Tolerância: slot ainda dispara até N minutos depois do horário. */
    public const int GRACE_MINUTES = 5;

    public function __construct(private PosterRegistryService $posters) {}

    public function dispatchDueSlots(): void
    {
        $now = CarbonImmutable::now();

        $due = ScheduleSlot::query()
            ->due($now, self::GRACE_MINUTES)
            ->orderBy('slot_time')
            ->get();

        foreach ($due as $slot) {
            $this->dispatchSlot($slot);
        }

        if (AppSetting::isEnabled(AppSetting::RANDOM_MODE)) {
            $this->fillDueEmptySlots($now);
        }
    }

    /**
     * MODO ALEATÓRIO (flag na /agenda): slot vazio devido recebe um vídeo
     * pronto sorteado e roda o fluxo antigo à parte — pegar o vídeo,
     * reencodar e postar (ReencodeAndPostSlotJob). Atribuição e claim na
     * MESMA UPDATE atômica: sem isso o tick seguinte veria o slot "com vídeo
     * e não despachado" e postaria o original em paralelo com o reencode.
     * Vídeos com post ativo (queued/completed/restricted...) ficam fora do
     * sorteio — restrito não volta pro pool.
     */
    private function fillDueEmptySlots(CarbonImmutable $now): void
    {
        if ($this->posters->enabled() === []) {
            return;
        }

        $empty = ScheduleSlot::query()
            ->dueEmpty($now, self::GRACE_MINUTES)
            ->orderBy('slot_time')
            ->get();

        foreach ($empty as $slot) {
            $short = YoutubeShort::query()
                ->readyToSchedule()
                ->whereNotIn('youtube_id', SocialPost::query()->active()->select('youtube_id'))
                // Sorteio em andamento: slot já reivindicado mas ainda sem
                // ledger (reencode rodando) também segura o vídeo — sem isso
                // o mesmo short entraria em 2 slots até o fan-out criar as
                // social_posts.
                ->whereDoesntHave('scheduleSlots', fn (Builder $q) => $q
                    ->whereNotNull('dispatched_at')
                    ->whereDoesntHave('socialPosts'))
                ->inRandomOrder()
                ->first();

            if (! $short instanceof YoutubeShort) {
                Log::warning('[AutoPost][Random] Sem vídeo pronto no estoque — slot vazio fica pulado.', ['slot_id' => $slot->id]);

                break;
            }

            $claimed = ScheduleSlot::query()
                ->whereKey($slot->id)
                ->whereNull('youtube_short_id')
                ->whereNull('dispatched_at')
                ->update(['youtube_short_id' => $short->id, 'dispatched_at' => now()]);

            if ($claimed !== 1) {
                continue;
            }

            Log::info('[AutoPost][Random] Vídeo sorteado pro slot vazio.', ['slot_id' => $slot->id, 'short_id' => $short->id]);
            dispatch(new ReencodeAndPostSlotJob($slot->id));
        }
    }

    /**
     * Reivindica o slot e enfileira os posts. Retorna false quando outro
     * tick/clique já reivindicou (ou o slot não tem vídeo).
     */
    public function dispatchSlot(ScheduleSlot $slot): bool
    {
        if ($this->posters->enabled() === []) {
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

        $this->fanOut($slot);

        return true;
    }

    /**
     * Enfileira 1 PostSlotToPlatformJob por plataforma habilitada. Chamar só
     * com o slot JÁ reivindicado (dispatchSlot, ou o claim do modo aleatório
     * via ReencodeAndPostSlotJob pós-reencode).
     */
    public function fanOut(ScheduleSlot $slot): void
    {
        $enabled = $this->posters->enabled();

        Log::info('[AutoPost] Slot despachado.', [
            'slot_id' => $slot->id,
            'platforms' => array_map(static fn (PosterInterface $p): string => $p->platform(), $enabled),
        ]);

        foreach ($enabled as $poster) {
            dispatch(new PostSlotToPlatformJob($slot->id, $poster->platform()));
        }
    }
}
