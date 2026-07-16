<?php

declare(strict_types=1);

use App\Models\ScheduleSlot;
use App\Models\SocialPost;
use App\Models\YoutubeShort;
use App\Services\AutoPost\SlotStatusService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;

beforeEach(function (): void {
    Date::setTestNow(CarbonImmutable::parse('2026-07-15 12:00:00'));
});

function slotWith(array $attributes = []): ScheduleSlot
{
    return ScheduleSlot::factory()->create([
        'slot_date' => '2026-07-15',
        'slot_time' => '09:00:00',
        'youtube_short_id' => YoutubeShort::factory()->ready()->create()->id,
        ...$attributes,
    ]);
}

function statusOf(ScheduleSlot $slot): array
{
    return SlotStatusService::resolve($slot->fresh(['socialPosts', 'youtubeShort']), CarbonImmutable::now());
}

it('resolves pre-dispatch states', function (): void {
    expect(statusOf(slotWith(['slot_time' => '18:00:00']))['status'])->toBe('future')
        ->and(statusOf(slotWith(['slot_time' => '09:00:00']))['status'])->toBe('skipped')
        ->and(statusOf(slotWith(['slot_time' => '11:58:00']))['status'])->toBe('due')
        ->and(statusOf(slotWith(['slot_time' => '10:00:00', 'youtube_short_id' => null]))['status'])->toBe('empty')
        ->and(statusOf(slotWith(['slot_time' => '19:00:00', 'is_active' => false]))['status'])->toBe('paused')
        ->and(statusOf(slotWith(['slot_time' => '08:00:00', 'is_active' => false]))['status'])->toBe('skipped');
});

it('aggregates per-platform results after dispatch', function (): void {
    $slot = slotWith(['slot_time' => '09:30:00', 'dispatched_at' => now()]);

    $post = fn (string $platform, string $status, ?string $error = null) => SocialPost::factory()->create([
        'schedule_slot_id' => $slot->id,
        'platform' => $platform,
        'status' => $status,
        'error' => $error,
    ]);

    // Sem ledger ainda: os jobs estão na fila.
    expect(statusOf($slot)['status'])->toBe('posting');

    $youtube = $post('youtube', 'completed');
    expect(statusOf($slot)['status'])->toBe('posted');

    $tiktok = $post('tiktok', 'processing');
    expect(statusOf($slot)['status'])->toBe('posting');

    $tiktok->update(['status' => 'failed', 'error' => 'Sessão inválida']);
    $resolved = statusOf($slot);
    expect($resolved['status'])->toBe('partial')
        ->and($resolved['platforms'])->toHaveCount(2)
        ->and(collect($resolved['platforms'])->firstWhere('platform', 'tiktok')['reason'])->toBe('Sessão inválida');

    $youtube->update(['status' => 'failed', 'error' => 'Token expirado']);
    expect(statusOf($slot)['status'])->toBe('failed');
});
