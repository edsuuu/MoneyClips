<?php

declare(strict_types=1);

use App\Livewire\Schedule\Index;
use App\Models\ScheduleSlot;
use App\Models\User;
use App\Models\YoutubeShort;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Livewire\Livewire;

beforeEach(function (): void {
    Date::setTestNow(CarbonImmutable::parse('2026-07-15 12:00:00'));
    $this->actingAs(User::factory()->create());
});

it('saves the draft as schedule slots', function (): void {
    $short = YoutubeShort::factory()->ready()->create();

    Livewire::test(Index::class)
        ->call('addSlot', '2026-07-16')
        ->set('days.2026-07-16.0.time', '14:30')
        ->set('days.2026-07-16.0.short_id', $short->id)
        ->call('save');

    $slot = ScheduleSlot::query()->sole();
    expect($slot->slot_date->toDateString())->toBe('2026-07-16')
        ->and($slot->timeLabel())->toBe('14:30')
        ->and($slot->youtube_short_id)->toBe($short->id)
        ->and($slot->is_active)->toBeTrue();
});

it('rejects duplicated times and enforces the per-day cap', function (): void {
    $component = Livewire::test(Index::class);

    foreach (range(1, ScheduleSlot::MAX_PER_DAY) as $i) {
        $component->call('addSlot', '2026-07-16')
            ->set('days.2026-07-16.'.($i - 1).'.time', sprintf('1%d:00', $i));
    }

    // 6º horário no mesmo dia é bloqueado.
    $component->call('addSlot', '2026-07-16');
    expect($component->get('days')['2026-07-16'])->toHaveCount(ScheduleSlot::MAX_PER_DAY);

    // Horário duplicado não salva nada.
    $component->set('days.2026-07-16.1.time', '11:00')->call('save');
    expect(ScheduleSlot::query()->count())->toBe(0);
});

it('marks dirty on edits', function (): void {
    $component = Livewire::test(Index::class)
        ->call('addSlot', '2026-07-17');

    expect($component->get('dirty'))->toBeTrue();
});

it('force dispatches a skipped slot', function (): void {
    $short = YoutubeShort::factory()->ready()->create();
    $slot = ScheduleSlot::factory()->create([
        'slot_date' => '2026-07-15',
        'slot_time' => '09:00:00',
        'youtube_short_id' => $short->id,
    ]);

    Livewire::test(Index::class)->call('forceDispatch', $slot->id);

    expect($slot->refresh()->dispatched_at)->not->toBeNull();
});
