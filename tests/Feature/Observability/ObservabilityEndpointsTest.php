<?php

declare(strict_types=1);

use App\Models\ServiceHeartbeat;
use App\Models\ServiceLog;

beforeEach(function (): void {
    config()->set('services.observability.token', 'secret-token');
});

it('rejects requests without a valid token and fails closed without config', function (): void {
    $payload = ['service' => 'reencode', 'uptime_seconds' => 10];

    $this->postJson('/api/observability/heartbeat', $payload)->assertUnauthorized();
    $this->postJson('/api/observability/heartbeat', $payload, ['X-Observability-Token' => 'wrong'])->assertUnauthorized();

    config()->set('services.observability.token', '');
    $this->postJson('/api/observability/heartbeat', $payload, ['X-Observability-Token' => ''])->assertServiceUnavailable();
});

it('upserts one heartbeat row per service', function (): void {
    $headers = ['X-Observability-Token' => 'secret-token'];

    $this->postJson('/api/observability/heartbeat', [
        'service' => 'tiktok-uploader',
        'hostname' => 'vps-b',
        'version' => '1.0.0',
        'uptime_seconds' => 120,
        'memory_mb' => 245,
    ], $headers)->assertOk();

    $this->postJson('/api/observability/heartbeat', [
        'service' => 'tiktok-uploader',
        'uptime_seconds' => 150,
    ], $headers)->assertOk();

    $heartbeat = ServiceHeartbeat::query()->sole();
    expect($heartbeat->service)->toBe('tiktok-uploader')
        ->and($heartbeat->uptime_seconds)->toBe(150)
        ->and($heartbeat->isOnline())->toBeTrue();
});

it('stores log batches in a single insert', function (): void {
    $this->postJson('/api/observability/logs', [
        'service' => 'reencode',
        'hostname' => 'vps-d',
        'entries' => [
            ['level' => 'info', 'message' => 'Reencode iniciado', 'logged_at' => now()->toIso8601String()],
            ['level' => 'error', 'message' => 'ffmpeg saiu com código 1', 'context' => ['trace' => ['linha 1', 'linha 2']]],
        ],
    ], ['X-Observability-Token' => 'secret-token'])
        ->assertOk()
        ->assertJson(['stored' => 2]);

    expect(ServiceLog::query()->count())->toBe(2)
        ->and(ServiceLog::query()->where('level', 'error')->sole()->context)->toBe(['trace' => ['linha 1', 'linha 2']]);
});

it('prunes logs older than the retention window', function (): void {
    ServiceLog::query()->insert([
        ['service' => 'reencode', 'level' => 'info', 'message' => 'velho', 'logged_at' => now()->subDays(20), 'created_at' => now()->subDays(20)],
        ['service' => 'reencode', 'level' => 'info', 'message' => 'novo', 'logged_at' => now(), 'created_at' => now()],
    ]);

    $this->artisan('model:prune', ['--model' => [ServiceLog::class]])->assertSuccessful();

    expect(ServiceLog::query()->pluck('message')->all())->toBe(['novo']);
});
