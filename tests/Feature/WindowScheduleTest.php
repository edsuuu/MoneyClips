<?php

declare(strict_types=1);

use App\Models\User;
use App\Services\AutoPost\WindowSchedule;
use Illuminate\Support\Facades\Date;
use Livewire\Livewire;

// 2026-07-10 é sexta (ISO 5); 2026-07-11 é sábado (ISO 6).

it('sem agenda no banco usa o fallback: horas fixas com minuto sorteado', function (): void {
    User::factory()->create(['auto_post_schedule' => null]);

    $day = Date::parse('2026-07-10 00:00', WindowSchedule::TIMEZONE);
    $expected = array_map(
        static fn (int $hour): string => sprintf('%02d:%02d', $hour, WindowSchedule::minuteFor($day, $hour)),
        WindowSchedule::DEFAULT_SLOT_HOURS,
    );

    expect(WindowSchedule::timesFor($day))->toBe($expected);
});

it('usa os horários do dia da semana configurados no banco', function (): void {
    User::factory()->create(['auto_post_schedule' => [5 => ['21:00', '08:30']]]);

    $friday = Date::parse('2026-07-10 00:00', WindowSchedule::TIMEZONE);
    $saturday = Date::parse('2026-07-11 00:00', WindowSchedule::TIMEZONE);

    expect(WindowSchedule::timesFor($friday))->toBe(['08:30', '21:00'])
        ->and(WindowSchedule::timesFor($saturday))->toBe([]);
});

it('sanitiza a agenda do banco: deduplica, ordena e descarta lixo', function (): void {
    User::factory()->create(['auto_post_schedule' => [5 => ['21:00', '08:30', '08:30', '25:99', 123, 'abc']]]);

    $friday = Date::parse('2026-07-10 00:00', WindowSchedule::TIMEZONE);

    expect(WindowSchedule::timesFor($friday))->toBe(['08:30', '21:00']);
});

it('dispara só no minuto exato do horário configurado, e a chave da janela inclui o minuto', function (): void {
    User::factory()->create(['auto_post_schedule' => [5 => ['08:30']]]);

    $friday = Date::parse('2026-07-10 00:00', WindowSchedule::TIMEZONE);

    expect(WindowSchedule::isDueWindow($friday->setTime(8, 30)))->toBeTrue()
        ->and(WindowSchedule::isDueWindow($friday->setTime(8, 31)))->toBeFalse()
        ->and(WindowSchedule::isDueWindow($friday->addDay()->setTime(8, 30)))->toBeFalse()
        ->and(WindowSchedule::windowKey($friday->setTime(8, 30)))->toBe('auto-post:window:2026-07-10:08:30');
});

it('salva a agenda semanal pela UI da /agenda (ordenada e deduplicada)', function (): void {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(App\Livewire\Schedule\Index::class)
        ->set('scheduleTimes.1', ['10:15', '09:00', '09:00'])
        ->call('saveSchedule');

    expect($user->refresh()->auto_post_schedule[1])->toBe(['09:00', '10:15']);
});
