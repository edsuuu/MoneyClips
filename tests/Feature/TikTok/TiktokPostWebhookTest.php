<?php

declare(strict_types=1);

use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\YoutubeShort;

function queuedTiktokLedger(array $attributes = []): SocialPost
{
    $short = YoutubeShort::factory()->ready()->create();

    return SocialPost::factory()->create([
        'platform' => 'tiktok',
        'uuid' => 'job-123',
        'youtube_id' => $short->youtube_id,
        'status' => 'queued',
        'posted_at' => null,
        ...$attributes,
    ]);
}

function webhookAccount(array $attributes = []): SocialAccount
{
    return SocialAccount::query()->create([
        'platform' => 'tiktok',
        'name' => 'conta-teste',
        'is_active' => true,
        'cookies' => [['name' => 'sessionid', 'value' => 'old', 'domain' => '.tiktok.com']],
        'session_status' => SocialAccount::SESSION_UNKNOWN,
        ...$attributes,
    ]);
}

it('settles a completed post: ledger, short and refreshed session', function (): void {
    $ledger = queuedTiktokLedger();
    $account = webhookAccount();

    $this->postJson('/api/tiktok-posts/webhook', [
        'job_id' => 'job-123',
        'status' => 'completed',
        'session_status' => 'valid',
        'refreshed_cookies' => [['name' => 'sessionid', 'value' => 'new', 'domain' => '.tiktok.com']],
    ])->assertOk();

    $ledger->refresh();
    expect($ledger->status)->toBe('completed')
        ->and($ledger->posted_at)->not->toBeNull()
        ->and(YoutubeShort::query()->where('youtube_id', $ledger->youtube_id)->value('posted_tiktok_at'))->not->toBeNull();

    $account->refresh();
    expect($account->session_status)->toBe(SocialAccount::SESSION_VALID)
        ->and($account->cookies_last_validated_at)->not->toBeNull()
        ->and($account->cookies[0]['value'] ?? null)->toBe('new');
});

it('records a restricted post without touching the short or the session', function (): void {
    $ledger = queuedTiktokLedger();
    webhookAccount(['session_status' => SocialAccount::SESSION_VALID]);

    $this->postJson('/api/tiktok-posts/webhook', [
        'job_id' => 'job-123',
        'status' => 'restricted',
        'detail' => 'modal de moderação',
        'session_status' => 'valid',
    ])->assertOk();

    $ledger->refresh();
    expect($ledger->status)->toBe('restricted')
        ->and($ledger->error)->toBe('modal de moderação')
        ->and($ledger->posted_at)->toBeNull()
        ->and(YoutubeShort::query()->where('youtube_id', $ledger->youtube_id)->value('posted_tiktok_at'))->toBeNull();
});

it('marks the account invalid when the uploader reports a dead session', function (): void {
    $ledger = queuedTiktokLedger();
    $account = webhookAccount(['session_status' => SocialAccount::SESSION_VALID]);

    $this->postJson('/api/tiktok-posts/webhook', [
        'job_id' => 'job-123',
        'status' => 'failed',
        'error' => 'redirect para login',
        'session_status' => 'invalid',
    ])->assertOk();

    expect($ledger->refresh()->status)->toBe('failed')
        ->and($account->refresh()->session_status)->toBe(SocialAccount::SESSION_INVALID);
});

it('returns 404 for an unknown job_id', function (): void {
    $this->postJson('/api/tiktok-posts/webhook', [
        'job_id' => 'nope',
        'status' => 'completed',
    ])->assertNotFound();
});

it('is idempotent: a retry after the outcome does not reopen the ledger', function (): void {
    $ledger = queuedTiktokLedger(['status' => 'completed', 'posted_at' => now()->subHour()]);

    $this->postJson('/api/tiktok-posts/webhook', [
        'job_id' => 'job-123',
        'status' => 'failed',
        'error' => 'retry atrasado',
    ])->assertOk()->assertJson(['status' => 'already-finished']);

    expect($ledger->refresh()->status)->toBe('completed');
});
