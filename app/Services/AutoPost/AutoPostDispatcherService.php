<?php

declare(strict_types=1);

namespace App\Services\AutoPost;

use App\Jobs\PostSlotToPlatformJob;
use App\Jobs\ReencodeAndPostSlotJob;
use App\Models\ScheduleSlot;
use App\Models\SocialPost;
use App\Models\YoutubeShort;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

final readonly class AutoPostDispatcherService
{
    public const int GRACE_MINUTES = 5;

    public const string RANDOM_MODE = 'random_mode';

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

        if (Cache::get(self::RANDOM_MODE, false)) {
            $this->fillDueEmptySlots($now);
        }
    }

    private function fillDueEmptySlots(CarbonImmutable $now): void
    {
        $empty = ScheduleSlot::query()
            ->dueEmpty($now, self::GRACE_MINUTES)
            ->orderBy('slot_time')
            ->get();

        foreach ($empty as $slot) {
            $short = YoutubeShort::query()
                ->readyToSchedule()
                ->whereNotIn('youtube_id', SocialPost::query()->active()->select('youtube_id'))

                ->whereDoesntHave('scheduleSlots', fn (Builder $q) => $q
                    ->whereNotNull('dispatched_at')
                    ->whereDoesntHave('socialPosts'))
                ->inRandomOrder()
                ->first();

            if (! $short instanceof YoutubeShort) {
                Log::channel('daily')->warning('[WARN][AutoPost][Random] Sem vídeo pronto no estoque — slot vazio fica pulado.', ['slot_id' => $slot->id]);

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

            Log::channel('daily')->info('[INFO][AutoPost][Random] Vídeo sorteado pro slot vazio.', ['slot_id' => $slot->id, 'short_id' => $short->id]);
            dispatch(new ReencodeAndPostSlotJob($slot->id));
        }
    }

    public function dispatchSlot(ScheduleSlot $slot): bool
    {
        $claimed = ScheduleSlot::query()
            ->whereKey($slot->id)
            ->whereNull('dispatched_at')
            ->whereNotNull('youtube_short_id')
            ->update(['dispatched_at' => now()]);

        if ($claimed !== 1) {
            Log::channel('daily')->info('[INFO][AutoPost] Slot já reivindicado ou sem vídeo — ignorando.', ['slot_id' => $slot->id]);

            return false;
        }

        $this->fanOut($slot);

        return true;
    }

    public function fanOut(ScheduleSlot $slot): void
    {
        $posters = $this->posters->all();

        Log::channel('daily')->info('[INFO][AutoPost] Slot despachado.', [
            'slot_id' => $slot->id,
            'platforms' => array_map(static fn (PosterInterface $p): string => $p->platform(), $posters),
        ]);

        foreach ($posters as $poster) {
            dispatch(new PostSlotToPlatformJob($slot->id, $poster->platform()));
        }
    }
}
