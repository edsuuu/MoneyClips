<?php

declare(strict_types=1);

use App\Models\ServiceLog;
use App\Models\User;

beforeEach(function (): void {
    config()->set('services.observability.token', 'secret-token');
});

it('rejects requests without a valid token and fails closed without config', function (): void {
    $payload = ['service' => 'reencode', 'entries' => [['level' => 'info', 'message' => 'oi']]];

    $this->postJson('/api/observability/logs', $payload)->assertUnauthorized();
    $this->postJson('/api/observability/logs', $payload, ['X-Observability-Token' => 'wrong'])->assertUnauthorized();

    config()->set('services.observability.token', '');
    $this->postJson('/api/observability/logs', $payload, ['X-Observability-Token' => ''])->assertServiceUnavailable();
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
        ->assertExactJson(['status' => 'ok', 'stored' => 2]);

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

it('keeps the browser log endpoint on session auth, not the service token', function (): void {
    $payload = ['level' => 'error', 'message' => 'boom'];

    $this->postJson('/client-logs', $payload)->assertUnauthorized();
    $this->postJson('/client-logs', $payload, ['X-Observability-Token' => 'secret-token'])->assertUnauthorized();

    $this->actingAs(User::factory()->create());

    $this->postJson('/client-logs', $payload)->assertOk()->assertExactJson(['status' => 'logged']);
    $this->postJson('/client-logs', ['level' => 'debug', 'message' => 'x'])->assertJsonValidationErrors('level');
});
