<?php

declare(strict_types=1);

use App\Models\ScheduleSlot;
use App\Models\SocialPost;
use App\Models\YoutubeShort;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Date::setTestNow(CarbonImmutable::parse('2026-07-15 12:00:00', 'America/Sao_Paulo'));
    config()->set('services.youtube_shorts.discord_webhook', 'https://discord.test/webhook');
    Http::fake(['discord.test/*' => Http::response()]);
});

it('alerts once per skipped slot with a video', function (): void {
    ScheduleSlot::factory()->create([
        'slot_date' => '2026-07-15',
        'slot_time' => '09:00:00',
        'youtube_short_id' => YoutubeShort::factory()->ready()->create()->id,
    ]);

    $this->artisan('auto-post:check-missed')->assertSuccessful();
    $this->artisan('auto-post:check-missed')->assertSuccessful();

    Http::assertSentCount(1);
});

it('alerts about empty active slots and fully failed slots', function (): void {
    // Slot ativo sem vídeo que passou em branco.
    ScheduleSlot::factory()->create(['slot_date' => '2026-07-15', 'slot_time' => '10:00:00']);

    // Slot despachado onde todas as plataformas falharam.
    $failedSlot = ScheduleSlot::factory()->dispatched()->create([
        'slot_date' => '2026-07-15',
        'slot_time' => '09:30:00',
        'youtube_short_id' => YoutubeShort::factory()->ready()->create()->id,
    ]);
    SocialPost::factory()->create(['schedule_slot_id' => $failedSlot->id, 'platform' => 'youtube', 'status' => 'failed', 'error' => 'Token expirado']);
    SocialPost::factory()->create(['schedule_slot_id' => $failedSlot->id, 'platform' => 'tiktok', 'status' => 'failed', 'error' => 'Sessão inválida']);

    $this->artisan('auto-post:check-missed')->assertSuccessful();

    Http::assertSentCount(2);
});

it('stays silent for future, inactive and partially posted slots', function (): void {
    ScheduleSlot::factory()->create([
        'slot_date' => '2026-07-15',
        'slot_time' => '20:00:00',
        'youtube_short_id' => YoutubeShort::factory()->ready()->create()->id,
    ]);
    ScheduleSlot::factory()->inactive()->create(['slot_date' => '2026-07-15', 'slot_time' => '09:15:00']);

    $partial = ScheduleSlot::factory()->dispatched()->create([
        'slot_date' => '2026-07-15',
        'slot_time' => '09:45:00',
        'youtube_short_id' => YoutubeShort::factory()->ready()->create()->id,
    ]);
    SocialPost::factory()->create(['schedule_slot_id' => $partial->id, 'platform' => 'youtube', 'status' => 'completed']);
    SocialPost::factory()->create(['schedule_slot_id' => $partial->id, 'platform' => 'tiktok', 'status' => 'failed']);

    $this->artisan('auto-post:check-missed')->assertSuccessful();

    Http::assertNothingSent();
});
