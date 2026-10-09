<?php

declare(strict_types=1);

use App\Enums\PostStatusEnum;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\YoutubeShort;
use App\Services\TikTokUploader\TikTokUploaderPostService;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;

const TT_COOKIE = 'tt-secret-sessionid-value';

const TT_REFRESHED = 'tt-refreshed-sessionid-value';

function tiktokAccount(): SocialAccount
{
    return SocialAccount::query()->create([
        'platform' => 'tiktok',
        'name' => '@clips',
        'cookies' => [['name' => 'sessionid', 'value' => TT_COOKIE]],
        'session_status' => SocialAccount::SESSION_VALID,
    ]);
}

/**
 * @param  array<string, mixed>  $attributes
 */
function tiktokPost(SocialAccount $account, array $attributes = []): SocialPost
{
    $short = YoutubeShort::factory()->create(['title' => 'Corte bom #podcast', 'hashtags' => ['#shorts', '#podcast']]);
    Storage::disk('s3')->put($short->video_path, 'mp4');

    return SocialPost::query()->create([
        'youtube_short_id' => $short->id,
        'social_account_id' => $account->id,
        'scheduled_for' => now(),
        'status' => PostStatusEnum::Scheduled,
        ...$attributes,
    ]);
}

/**
 * @param  array<string, mixed>  $payload
 */
function tiktokWebhook(array $payload): TestResponse
{
    return test()->postJson('/api/webhook/tiktok-post', $payload, ['X-Observability-Token' => 'obs-token']);
}

/**
 * @return Closure(): string
 */
function tiktokLogs(): Closure
{
    $lines = [];
    Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$lines): void {
        $lines[] = $event->message.' '.json_encode($event->context);
    });

    return function () use (&$lines): string {
        return implode("\n", $lines);
    };
}

beforeEach(function (): void {
    Date::setTestNow('2026-10-08 20:00:00');
    Storage::fake('s3');
    config([
        'services.observability.token' => 'obs-token',
        'services.tiktok_uploader.base_url' => 'http://uploader.test',
        'services.tiktok_uploader.api_token' => 'uploader-token',
        'services.tiktok_uploader.webhook_url' => 'https://app.test/api/webhook/tiktok-post',
        'services.discord.webhook' => 'https://discord.test/webhook',
    ]);
    Http::fake(['discord.test/*' => Http::response()]);
});

it('sends the video multipart with cookies, title and hashtags and leaves the post in Posting with the job_id', function (): void {
    Http::fake(['uploader.test/posts' => Http::response(['job_id' => 'job-1', 'status' => 'queued'], 202)]);
    $logs = tiktokLogs();
    $account = tiktokAccount();
    $post = tiktokPost($account);

    $this->artisan('posts:dispatch')->assertSuccessful();

    expect($post->refresh()->status)->toBe(PostStatusEnum::Posting)
        ->and($post->external_id)->toBe('job-1')
        ->and($logs())->not->toContain(TT_COOKIE);

    Http::assertSent(function (Request $request) use ($account): bool {
        if ($request->url() !== 'http://uploader.test/posts') {
            return false;
        }

        $fields = collect($request->data())->mapWithKeys(fn (array $part): array => [$part['name'] => $part['contents']]);

        return $request->hasHeader('Authorization', 'Bearer uploader-token')
            && $request->isMultipart()
            && $fields->has('video')
            && $fields['title'] === 'Corte bom #podcast'
            && $fields['hashtags'] === '["#shorts"]'
            && json_decode((string) $fields['cookies'], true) === [['name' => 'sessionid', 'value' => TT_COOKIE]]
            && $fields['webhook_url'] === 'https://app.test/api/webhook/tiktok-post'
            && $fields['account_id'] === $account->uuid;
    });
});

it('fails without calling the uploader when the account has no cookies', function (): void {
    $account = SocialAccount::query()->create(['platform' => 'tiktok', 'name' => '@vazia']);

    $result = resolve(TikTokUploaderPostService::class)->post(tiktokPost($account), (string) tempnam(sys_get_temp_dir(), 'tt-'));

    expect($result->status)->toBe(PostStatusEnum::Failed)->and($result->error)->toContain('sem cookies');
    Http::assertNothingSent();
});

it('fails with the validation the uploader returned', function (): void {
    Http::fake(['uploader.test/posts' => Http::response(['errors' => ['video' => 'Arquivo de vídeo obrigatório.']], 422)]);
    $account = tiktokAccount();

    $result = resolve(TikTokUploaderPostService::class)->post(tiktokPost($account), (string) tempnam(sys_get_temp_dir(), 'tt-'));

    expect($result->status)->toBe(PostStatusEnum::Failed)
        ->and($result->error)->toContain('HTTP 422')->toContain('Arquivo de vídeo obrigatório')
        ->and($result->error)->not->toContain(TT_COOKIE);
});

it('fails readably when the uploader is down', function (): void {
    Http::fake(['uploader.test/posts' => Http::failedConnection()]);

    $result = resolve(TikTokUploaderPostService::class)->post(tiktokPost(tiktokAccount()), (string) tempnam(sys_get_temp_dir(), 'tt-'));

    expect($result->status)->toBe(PostStatusEnum::Failed)->and($result->error)->toContain('TikTokUploader inacessível');
});

it('publishes on a completed webhook, stores the refreshed cookies encrypted and marks posted_tiktok_at', function (): void {
    $logs = tiktokLogs();
    $account = tiktokAccount();
    $post = tiktokPost($account, ['status' => PostStatusEnum::Posting, 'external_id' => 'job-1']);

    tiktokWebhook([
        'job_id' => 'job-1',
        'status' => 'completed',
        'title' => 'Corte bom',
        'session_status' => 'valid',
        'refreshed_cookies' => [['name' => 'sessionid', 'value' => TT_REFRESHED]],
    ])->assertOk()->assertExactJson(['status' => 'post-closed']);

    $post->refresh();
    $account->refresh();
    expect($post->status)->toBe(PostStatusEnum::Published)
        ->and($post->privacy)->toBe('public')
        ->and($post->youtubeShort->posted_tiktok_at)->not->toBeNull()
        ->and($account->cookies)->toBe([['name' => 'sessionid', 'value' => TT_REFRESHED]])
        ->and($account->getRawOriginal('cookies'))->not->toContain(TT_REFRESHED)
        ->and($account->cookies_last_validated_at)->not->toBeNull()
        ->and($logs())->not->toContain(TT_REFRESHED)->not->toContain(TT_COOKIE);

    $discord = Http::recorded(fn (Request $request): bool => str_contains($request->url(), 'discord.test'));
    expect($discord->first()[0]->body())->not->toContain(TT_REFRESHED);
});

it('fails the post and marks the session expired when the uploader says the cookies died', function (): void {
    $account = tiktokAccount();
    $post = tiktokPost($account, ['status' => PostStatusEnum::Posting, 'external_id' => 'job-1']);
    $next = tiktokPost($account, ['scheduled_for' => now()->addMinutes(5)]);

    tiktokWebhook(['job_id' => 'job-1', 'status' => 'failed', 'title' => 'x', 'error' => 'Login exigido', 'session_status' => 'invalid'])
        ->assertOk();

    expect($post->refresh()->status)->toBe(PostStatusEnum::Failed)
        ->and($post->error)->toContain('Sessão do TikTok expirada')->toContain('Login exigido')
        ->and($account->refresh()->session_status)->toBe(SocialAccount::SESSION_INVALID)
        ->and($account->cookies)->toBe([['name' => 'sessionid', 'value' => TT_COOKIE]]);

    Date::setTestNow(now()->addMinutes(5));
    $this->artisan('posts:dispatch')->assertSuccessful();
    expect($next->refresh()->status)->toBe(PostStatusEnum::Failed)
        ->and($next->error)->toContain('reconecte');
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'uploader.test'));
});

it('never marks a dry-run or a restricted post as published', function (string $status, string $expected): void {
    $post = tiktokPost(tiktokAccount(), ['status' => PostStatusEnum::Posting, 'external_id' => 'job-1']);

    tiktokWebhook(['job_id' => 'job-1', 'status' => $status, 'title' => 'x', 'detail' => 'Conteúdo restrito', 'session_status' => 'valid'])->assertOk();

    expect($post->refresh()->status)->toBe(PostStatusEnum::Failed)
        ->and($post->error)->toContain($expected)
        ->and($post->youtubeShort->posted_tiktok_at)->toBeNull();
})->with([
    'dry-run' => ['dry-run', 'DRY_RUN'],
    'restricted' => ['restricted', 'Conteúdo restrito'],
]);

it('keeps the account in sync but does not reopen a post the owner already closed', function (): void {
    $account = tiktokAccount();
    $post = tiktokPost($account, ['status' => PostStatusEnum::Canceled, 'external_id' => 'job-1']);

    tiktokWebhook([
        'job_id' => 'job-1', 'status' => 'completed', 'title' => 'x', 'session_status' => 'valid',
        'refreshed_cookies' => [['name' => 'sessionid', 'value' => TT_REFRESHED]],
    ])->assertOk()->assertExactJson(['status' => 'already-finished']);

    expect($post->refresh()->status)->toBe(PostStatusEnum::Canceled)
        ->and($post->youtubeShort->posted_tiktok_at)->toBeNull()
        ->and($account->refresh()->cookies)->toBe([['name' => 'sessionid', 'value' => TT_REFRESHED]]);
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'discord.test') && str_contains($request->body(), 'chegou tarde'));
});

it('publishes a late completed webhook over the unknown result the reaper recorded', function (): void {
    $logs = tiktokLogs();
    $post = tiktokPost(tiktokAccount(), ['status' => PostStatusEnum::Posting, 'external_id' => 'job-1', 'started_at' => now()->subHours(2)]);

    $this->artisan('posts:dispatch')->assertSuccessful();
    expect($post->refresh()->status)->toBe(PostStatusEnum::Failed)
        ->and($post->error)->toContain('Resultado desconhecido');

    tiktokWebhook(['job_id' => 'job-1', 'status' => 'completed', 'title' => 'x', 'session_status' => 'valid'])
        ->assertOk()->assertExactJson(['status' => 'post-closed']);

    $post->refresh();
    expect($post->status)->toBe(PostStatusEnum::Published)
        ->and($post->error)->toBeNull()
        ->and($post->privacy)->toBe('public')
        ->and($post->youtubeShort->posted_tiktok_at)->not->toBeNull()
        ->and($logs())->toContain('Resposta tardia');
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'uploader.test'));
});

it('ignores a repeated webhook without a false alarm on Discord', function (string $status): void {
    $post = tiktokPost(tiktokAccount(), ['status' => PostStatusEnum::Posting, 'external_id' => 'job-1']);
    $payload = ['job_id' => 'job-1', 'status' => $status, 'title' => 'x', 'error' => 'Falhou', 'session_status' => 'valid'];

    tiktokWebhook($payload)->assertOk()->assertExactJson(['status' => 'post-closed']);
    tiktokWebhook($payload)->assertOk()->assertExactJson(['status' => 'already-finished']);

    expect($post->refresh()->status)->not->toBe(PostStatusEnum::Posting);
    $discord = Http::recorded(fn (Request $request): bool => str_contains($request->url(), 'discord.test'));
    expect($discord)->toHaveCount(1)
        ->and($discord->first()[0]->body())->not->toContain('chegou tarde');
})->with(['completed', 'failed']);

it('answers 404 to an unknown job and 401 without the token', function (): void {
    tiktokWebhook(['job_id' => 'nope', 'status' => 'completed', 'session_status' => 'valid'])->assertNotFound();

    $this->postJson('/api/webhook/tiktok-post', ['job_id' => 'nope', 'status' => 'completed', 'session_status' => 'valid'])
        ->assertUnauthorized();
});
