<?php

declare(strict_types=1);

use App\Models\ScheduleSlot;
use App\Models\YoutubeShort;
use App\Services\AutoPost\WeekGenerator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;

beforeEach(function (): void {
    Date::setTestNow(CarbonImmutable::parse('2026-07-15 12:00:00', 'America/Sao_Paulo'));
});

it('creates default slots for an empty next week and assigns ready videos fifo', function (): void {
    $first = YoutubeShort::factory()->ready()->create(['ready_at' => now()->subDays(3)]);
    $second = YoutubeShort::factory()->ready()->create(['ready_at' => now()->subDays(2)]);
    $third = YoutubeShort::factory()->ready()->create(['ready_at' => now()->subDay()]);

    $nextMonday = CarbonImmutable::parse('2026-07-20', 'America/Sao_Paulo');
    $created = resolve(WeekGenerator::class)->generate($nextMonday, 2);

    // 5 horários default × 7 dias.
    expect($created)->toBe(35)
        ->and(ScheduleSlot::query()->count())->toBe(35);

    // FIFO: os 2 primeiros horários de segunda recebem os vídeos mais antigos.
    $mondaySlots = ScheduleSlot::query()->where('slot_date', '2026-07-20')->orderBy('slot_time')->get();
    expect($mondaySlots->first()->youtube_short_id)->toBe($first->id)
        ->and($mondaySlots[1]->youtube_short_id)->toBe($second->id)
        ->and($mondaySlots[2]->youtube_short_id)->toBeNull();

    // O estoque acabou no 3º vídeo (terça, 1º slot).
    $tuesdaySlots = ScheduleSlot::query()->where('slot_date', '2026-07-21')->orderBy('slot_time')->get();
    expect($tuesdaySlots->first()->youtube_short_id)->toBe($third->id)
        ->and($tuesdaySlots[1]->youtube_short_id)->toBeNull();
});

it('is idempotent per slot and respects the per-day cap', function (): void {
    $nextMonday = CarbonImmutable::parse('2026-07-20', 'America/Sao_Paulo');
    $generator = resolve(WeekGenerator::class);

    $generator->generate($nextMonday, 0);

    $again = $generator->generate($nextMonday, 0);

    expect($again)->toBe(0)
        ->and(ScheduleSlot::query()->count())->toBe(35);

    ScheduleSlot::query()
        ->get()
        ->groupBy(fn (ScheduleSlot $slot): string => $slot->slot_date->toDateString())
        ->each(function ($slots): void {
            expect($slots->count())->toBeLessThanOrEqual(ScheduleSlot::MAX_PER_DAY);
        });
});

it('copies times from the most recent week with slots', function (): void {
    ScheduleSlot::factory()->create(['slot_date' => '2026-07-14', 'slot_time' => '10:30:00']);
    ScheduleSlot::factory()->create(['slot_date' => '2026-07-14', 'slot_time' => '19:45:00']);

    $created = resolve(WeekGenerator::class)->generate(CarbonImmutable::parse('2026-07-20', 'America/Sao_Paulo'), 0);

    // Semana anterior só tinha horários na terça → nova semana idem.
    expect($created)->toBe(2);

    $times = ScheduleSlot::query()
        ->where('slot_date', '2026-07-21')
        ->orderBy('slot_time')
        ->pluck('slot_time')
        ->map(fn (string $time): string => mb_substr($time, 0, 5))
        ->all();

    expect($times)->toBe(['10:30', '19:45']);
});

it('skips times already in the past when filling the current week', function (): void {
    $monday = CarbonImmutable::parse('2026-07-13', 'America/Sao_Paulo');

    resolve(WeekGenerator::class)->generate($monday, 0);

    // Hoje é quarta 15/07 12:00 — nada é criado antes de agora.
    expect(ScheduleSlot::query()->where('slot_date', '<', '2026-07-15')->count())->toBe(0)
        ->and(ScheduleSlot::query()->where('slot_date', '2026-07-15')->pluck('slot_time')->map(fn (string $t): string => mb_substr($t, 0, 5))->all())
        ->toBe(['15:00', '18:00', '21:00']);
});
