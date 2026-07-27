<?php

declare(strict_types=1);

use App\Models\ServiceHeartbeat;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    config()->set('services.youtube_shorts.discord_webhook', 'https://discord.test/webhook');
    Http::fake(['discord.test/*' => Http::response()]);
});

it('alerts once per outage and announces recovery', function (): void {
    $heartbeat = ServiceHeartbeat::query()->create([
        'service' => 'reencode',
        'hostname' => 'vps-d',
        'uptime_seconds' => 100,
        'last_seen_at' => now()->subMinutes(10),
    ]);

    // Queda: alerta 1x, mesmo rodando o comando duas vezes.
    $this->artisan('observability:check-heartbeats')->assertSuccessful();
    $this->artisan('observability:check-heartbeats')->assertSuccessful();
    Http::assertSentCount(1);

    // Recuperação: 1 aviso e o dedupe é limpo.
    $heartbeat->update(['last_seen_at' => now()]);
    $this->artisan('observability:check-heartbeats')->assertSuccessful();
    $this->artisan('observability:check-heartbeats')->assertSuccessful();
    Http::assertSentCount(2);
});

it('stays silent while services are healthy', function (): void {
    ServiceHeartbeat::query()->create([
        'service' => 'download-youtube',
        'uptime_seconds' => 100,
        'last_seen_at' => now()->subSeconds(30),
    ]);

    $this->artisan('observability:check-heartbeats')->assertSuccessful();

    Http::assertNothingSent();
});
