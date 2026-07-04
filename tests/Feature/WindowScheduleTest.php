<?php

declare(strict_types=1);

use App\Models\User;
use App\Services\AutoPost\WindowSchedule;
use Illuminate\Support\Facades\Date;

it('usa o default quando o banco não tem horas', function (): void {
    User::factory()->create(['auto_post_slot_hours' => null]);

    expect(WindowSchedule::slotHours())->toBe(WindowSchedule::DEFAULT_SLOT_HOURS);
});

it('sanitiza as horas do banco: deduplica, ordena e descarta fora de 0-23', function (): void {
    User::factory()->create(['auto_post_slot_hours' => [21, 7, 7, 30, -1, 14]]);

    expect(WindowSchedule::slotHours())->toBe([7, 14, 21]);
});

it('cai no default quando o valor do banco só tem lixo', function (): void {
    User::factory()->create(['auto_post_slot_hours' => ['abc', 99]]);

    expect(WindowSchedule::slotHours())->toBe(WindowSchedule::DEFAULT_SLOT_HOURS);
});

it('dispara no minuto sorteado de uma hora vinda do banco e só nela', function (): void {
    User::factory()->create(['auto_post_slot_hours' => [8]]);

    $day = Date::parse('2026-07-10 00:00', WindowSchedule::TIMEZONE);
    $minute = WindowSchedule::minuteFor($day, 8);

    expect(WindowSchedule::isDueWindow($day->setTime(8, $minute)))->toBeTrue()
        ->and(WindowSchedule::isDueWindow($day->setTime(9, WindowSchedule::minuteFor($day, 9))))->toBeFalse();
});
