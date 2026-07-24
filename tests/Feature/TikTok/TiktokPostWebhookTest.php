<?php

declare(strict_types=1);

use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\YoutubeShort;
use Illuminate\Testing\TestResponse;

beforeEach(function (): void {
    config(['services.observability.token' => 'whk-token']);
});

function postWebhook(array $payload): TestResponse
{
    return test()->withHeader('X-Observability-Token', 'whk-token')
        ->postJson('/api/webhook/tiktok-posts', $payload);
}

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

it('rejects requests without the shared token', function (): void {
    queuedTiktokLedger();

    $this->postJson('/api/webhook/tiktok-posts', ['job_id' => 'job-123', 'status' => 'completed'])
        ->assertUnauthorized();

    expect(SocialPost::query()->sole()->status)->toBe('queued');
});

it('settles a completed post: ledger, short and refreshed session on the right account', function (): void {
    $ledger = queuedTiktokLedger();
    $account = webhookAccount();
    // Conta mais recente NÃO deve ser tocada — o webhook identifica pela account_id.
    $decoy = webhookAccount(['name' => 'conta-decoy']);

    postWebhook([
        'job_id' => 'job-123',
        'status' => 'completed',
        'session_status' => 'valid',
        'refreshed_cookies' => [['name' => 'sessionid', 'value' => 'new', 'domain' => '.tiktok.com']],
        'account_id' => (string) $account->id,
    ])->assertOk();

    $ledger->refresh();
    expect($ledger->status)->toBe('completed')
        ->and($ledger->posted_at)->not->toBeNull()
        ->and(YoutubeShort::query()->where('youtube_id', $ledger->youtube_id)->value('posted_tiktok_at'))->not->toBeNull();

    $account->refresh();
    expect($account->session_status)->toBe(SocialAccount::SESSION_VALID)
        ->and($account->cookies_last_validated_at)->not->toBeNull()
        ->and($account->cookies[0]['value'] ?? null)->toBe('new')
        ->and($decoy->refresh()->cookies[0]['value'] ?? null)->toBe('old');
});

it('records a restricted post without touching the short or the session', function (): void {
    $ledger = queuedTiktokLedger();
    webhookAccount(['session_status' => SocialAccount::SESSION_VALID]);

    postWebhook([
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

    postWebhook([
        'job_id' => 'job-123',
        'status' => 'failed',
        'error' => 'redirect para login',
        'session_status' => 'invalid',
        'account_id' => (string) $account->id,
    ])->assertOk();

    expect($ledger->refresh()->status)->toBe('failed')
        ->and($account->refresh()->session_status)->toBe(SocialAccount::SESSION_INVALID);
});

it('returns 404 for an unknown job_id', function (): void {
    postWebhook(['job_id' => 'nope', 'status' => 'completed'])->assertNotFound();
});

it('lets the real outcome overwrite a premature failed mark', function (): void {
    // Worker morreu entre o 202 e o webhook: failed() do job marcou o ledger.
    // O desfecho real (postado!) precisa vencer, senão repost = duplicado.
    $ledger = queuedTiktokLedger(['status' => 'failed', 'error' => 'job morreu']);

    postWebhook(['job_id' => 'job-123', 'status' => 'completed'])->assertOk();

    $ledger->refresh();
    expect($ledger->status)->toBe('completed')
        ->and($ledger->posted_at)->not->toBeNull()
        ->and(YoutubeShort::query()->where('youtube_id', $ledger->youtube_id)->value('posted_tiktok_at'))->not->toBeNull();
});

it('is idempotent: a retry after a final outcome does not reopen the ledger', function (): void {
    $ledger = queuedTiktokLedger(['status' => 'completed', 'posted_at' => now()->subHour()]);

    postWebhook(['job_id' => 'job-123', 'status' => 'failed', 'error' => 'retry atrasado'])
        ->assertOk()->assertJson(['status' => 'already-finished']);

    expect($ledger->refresh()->status)->toBe('completed');
});
