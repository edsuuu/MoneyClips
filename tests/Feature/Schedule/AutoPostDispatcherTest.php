<?php

declare(strict_types=1);

use App\Jobs\PostSlotToPlatformJob;
use App\Models\PlatformSetting;
use App\Models\ScheduleSlot;
use App\Models\YoutubeShort;
use App\Services\AutoPost\AutoPostDispatcherService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    Date::setTestNow(CarbonImmutable::parse('2026-07-15 12:00:00'));
    Queue::fake();
});

function dueSlot(array $attributes = []): ScheduleSlot
{
    return ScheduleSlot::factory()->create([
        'slot_date' => '2026-07-15',
        'slot_time' => '11:58:00',
        'youtube_short_id' => YoutubeShort::factory()->ready()->create()->id,
        ...$attributes,
    ]);
}

it('claims a due slot atomically and queues one job per enabled platform', function (): void {
    PlatformSetting::query()->where('platform', 'tiktok')->update(['enabled' => true]);
    $slot = dueSlot();

    resolve(AutoPostDispatcherService::class)->dispatchDueSlots();

    expect($slot->refresh()->dispatched_at)->not->toBeNull();
    Queue::assertPushed(PostSlotToPlatformJob::class, 2);
    Queue::assertPushed(fn (PostSlotToPlatformJob $job): bool => $job->slotId === $slot->id && $job->platform === 'youtube');
    Queue::assertPushed(fn (PostSlotToPlatformJob $job): bool => $job->slotId === $slot->id && $job->platform === 'tiktok');

    // Segundo disparo (tick duplicado / clique) perde o claim.
    expect(resolve(AutoPostDispatcherService::class)->dispatchSlot($slot->refresh()))->toBeFalse();
    Queue::assertPushed(PostSlotToPlatformJob::class, 2);
});

it('ignores slots outside the grace window, inactive or empty', function (): void {
    dueSlot(['slot_time' => '11:30:00']); // 30 min atrás — além da graça
    dueSlot(['slot_time' => '13:00:00']); // futuro
    dueSlot(['is_active' => false]);
    ScheduleSlot::factory()->create(['slot_date' => '2026-07-15', 'slot_time' => '11:59:00']); // sem vídeo

    resolve(AutoPostDispatcherService::class)->dispatchDueSlots();

    Queue::assertNothingPushed();
    expect(ScheduleSlot::query()->whereNotNull('dispatched_at')->count())->toBe(0);
});

it('does not claim when no platform is enabled', function (): void {
    PlatformSetting::query()->update(['enabled' => false]);
    $slot = dueSlot();

    resolve(AutoPostDispatcherService::class)->dispatchDueSlots();

    expect($slot->refresh()->dispatched_at)->toBeNull();
    Queue::assertNothingPushed();
});
