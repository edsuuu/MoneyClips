<?php

declare(strict_types=1);

use App\Models\YoutubeShort;
use App\Services\AutoPostDispatcher;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;

test('isDueWindow libera no minuto sorteado de cada janela', function (): void {
    $day = Date::create(2026, 6, 17, 0, 0, 0, AutoPostDispatcher::TIMEZONE);

    foreach (AutoPostDispatcher::WINDOWS as $hour) {
        $minute = AutoPostDispatcher::minuteFor($day, $hour);

        $due = $day->copy()->setTime($hour, $minute);
        expect(AutoPostDispatcher::isDueWindow($due))->toBeTrue();

        // Mesma janela, minuto diferente do sorteado → não libera.
        $off = $day->copy()->setTime($hour, ($minute + 1) % 60);
        expect(AutoPostDispatcher::isDueWindow($off))->toBeFalse();
    }
});

test('isDueWindow é falso fora das janelas', function (): void {
    $fora = Date::create(2026, 6, 17, 3, 30, 0, AutoPostDispatcher::TIMEZONE);

    expect(AutoPostDispatcher::isDueWindow($fora))->toBeFalse();
});

test('minuteFor é estável para o mesmo dia/hora e fica em 0–59', function (): void {
    $day = Date::create(2026, 6, 17, 0, 0, 0, AutoPostDispatcher::TIMEZONE);

    $a = AutoPostDispatcher::minuteFor($day, 9);
    $b = AutoPostDispatcher::minuteFor($day, 9);

    expect($a)->toBe($b)->and($a)->toBeGreaterThanOrEqual(0)->and($a)->toBeLessThan(60);
});

test('run não reserva nem posta quando YouTube e TikTok estão desativados', function (): void {
    config([
        'youtube_shorts.posting.youtube_enabled' => false,
        'youtube_shorts.posting.tiktok_enabled' => false,
    ]);

    $short = YoutubeShort::factory()->create(['video_path' => 'shorts/abc/short_abc.mp4']);

    resolve(AutoPostDispatcher::class)->run();

    expect($short->fresh()->dispatched_at)->toBeNull();
});

test('run não posta de novo na mesma janela (lock de idempotência)', function (): void {
    config(['youtube_shorts.posting.youtube_enabled' => false, 'youtube_shorts.posting.tiktok_enabled' => true]);

    // Simula que a janela atual JÁ foi processada (lock ocupado).
    Cache::add(AutoPostDispatcher::windowKey(), true, now()->addHour());

    $short = YoutubeShort::factory()->create(['video_path' => 'shorts/abc/short_abc.mp4']);

    resolve(AutoPostDispatcher::class)->run();

    // Lock segurou: não reservou nem postou nada.
    expect($short->fresh()->dispatched_at)->toBeNull();
});
