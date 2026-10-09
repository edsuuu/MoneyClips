<?php

declare(strict_types=1);

use App\Enums\PostProviderEnum;
use App\Enums\PostStatusEnum;
use App\Livewire\Accounts\Index;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\User;
use App\Models\YoutubeShort;
use App\Services\API\TikTok\TikTokPostService;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Livewire\Livewire;

const TTO_ACCESS = 'act.secret-tiktok-access';

const TTO_REFRESH = 'rft.secret-tiktok-refresh';

const TTO_API = 'open.tiktokapis.com/v2/post/publish/';

const TTO_UPLOAD = 'https://open-upload.tiktokapis.com/video/?upload_id=1&upload_token=abc';

/**
 * @param  array<string, mixed>  $attributes
 */
function tiktokOfficialAccount(array $attributes = []): SocialAccount
{
    return SocialAccount::query()->create([
        'platform' => 'tiktok',
        'provider' => PostProviderEnum::TiktokOfficial,
        'name' => 'Clips Oficial',
        'external_account_id' => 'open-id-1',
        'access_token' => TTO_ACCESS,
        'refresh_token' => TTO_REFRESH,
        'token_expires_at' => now()->addHours(12),
        'session_status' => SocialAccount::SESSION_VALID,
        ...$attributes,
    ]);
}

/**
 * @param  array<string, mixed>  $attributes
 */
function tiktokOfficialPost(SocialAccount $account, array $attributes = []): SocialPost
{
    $short = YoutubeShort::factory()->create(['title' => 'Corte bom', 'hashtags' => ['#shorts']]);
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
 * @param  array<string, mixed>  $data
 * @return array{data: array<string, mixed>, error: array{code: string, message: string, log_id: string}}
 */
function tiktokOk(array $data): array
{
    return ['data' => $data, 'error' => ['code' => 'ok', 'message' => '', 'log_id' => 'log-1']];
}

/**
 * @return array{data: array<string, mixed>, error: array{code: string, message: string, log_id: string}}
 */
function tiktokError(string $code, string $message = 'Rejected.'): array
{
    return ['data' => [], 'error' => ['code' => $code, 'message' => $message, 'log_id' => 'log-1']];
}

/**
 * @param  list<string>  $privacyOptions
 * @param  list<array<string, mixed>>  $statuses
 */
function fakeTiktokPosting(array $privacyOptions = ['SELF_ONLY'], array $statuses = [['status' => 'PUBLISH_COMPLETE', 'publicaly_available_post_id' => []]]): void
{
    $statusSequence = Http::sequence();
    foreach ($statuses as $status) {
        $statusSequence->push(tiktokOk($status));
    }

    Http::fake([
        'open.tiktokapis.com/v2/oauth/token/' => Http::response(['access_token' => 'act.fresh', 'refresh_token' => 'rft.fresh', 'expires_in' => 86400, 'open_id' => 'open-id-1', 'scope' => 'user.info.basic,video.publish']),
        TTO_API.'creator_info/query/' => Http::response(tiktokOk([
            'creator_username' => 'clips', 'privacy_level_options' => $privacyOptions,
            'comment_disabled' => false, 'duet_disabled' => true, 'stitch_disabled' => false, 'max_video_post_duration_sec' => 600,
        ])),
        TTO_API.'video/init/' => Http::response(tiktokOk(['publish_id' => 'v_pub_1', 'upload_url' => TTO_UPLOAD])),
        'open-upload.tiktokapis.com/*' => Http::response('', 201),
        TTO_API.'status/fetch/' => $statusSequence,
        'discord.test/*' => Http::response(),
    ]);
}

function tiktokOfficialFile(): string
{
    $path = (string) tempnam(sys_get_temp_dir(), 'tto-test-');
    file_put_contents($path, 'mp4-bytes');

    return $path;
}

/**
 * @return Closure(): string
 */
function tiktokOfficialLogs(): Closure
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
    Sleep::fake();
    config([
        'services.tiktok.client_key' => 'client-key',
        'services.tiktok.client_secret' => 'client-secret',
        'services.tiktok.redirect' => 'https://app.test/oauth/tiktok/callback',
        'services.discord.webhook' => 'https://discord.test/webhook',
    ]);
});

it('redirects to the Login Kit with the client key, scopes and a session state', function (): void {
    $this->actingAs(User::factory()->create());

    $response = $this->get(route('oauth.connect', ['platform' => 'tiktok']));

    $location = (string) $response->headers->get('Location');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
    expect($location)->toStartWith('https://www.tiktok.com/v2/auth/authorize/')
        ->and($query['client_key'])->toBe('client-key')
        ->and($query['scope'])->toBe('user.info.basic,video.publish')
        ->and($query['redirect_uri'])->toBe('https://app.test/oauth/tiktok/callback')
        ->and($query['state'])->toBe(session('oauth.tiktok.state'));
});

it('refuses to connect without the TikTok keys', function (): void {
    config(['services.tiktok.client_key' => null]);
    $this->actingAs(User::factory()->create());

    $this->get(route('oauth.connect', ['platform' => 'tiktok']))
        ->assertRedirect(route('accounts.index'))
        ->assertSessionHas('error', fn (string $error): bool => str_contains($error, 'TIKTOK_CLIENT_KEY'));
});

it('connects the account from the callback with encrypted tokens and provider tiktok_official', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user)->withSession(['oauth.tiktok.state' => 'state-1']);
    Http::fake([
        'open.tiktokapis.com/v2/oauth/token/' => Http::response(['access_token' => TTO_ACCESS, 'refresh_token' => TTO_REFRESH, 'expires_in' => 86400, 'open_id' => 'open-id-1', 'scope' => 'user.info.basic,video.publish']),
        'open.tiktokapis.com/v2/user/info/*' => Http::response(['data' => ['user' => ['open_id' => 'open-id-1', 'display_name' => 'Clips Oficial']], 'error' => ['code' => 'ok']]),
    ]);

    $this->get('/oauth/tiktok/callback?code=auth-code&state=state-1')
        ->assertRedirect(route('accounts.index'))
        ->assertSessionHas('status', 'Conta TikTok oficial conectada: Clips Oficial');

    $account = SocialAccount::query()->sole();
    expect($account->provider)->toBe(PostProviderEnum::TiktokOfficial)
        ->and($account->user_id)->toBe($user->id)
        ->and($account->external_account_id)->toBe('open-id-1')
        ->and($account->access_token)->toBe(TTO_ACCESS)
        ->and($account->getRawOriginal('access_token'))->not->toContain(TTO_ACCESS)
        ->and($account->getRawOriginal('refresh_token'))->not->toContain(TTO_REFRESH)
        ->and($account->session_status)->toBe(SocialAccount::SESSION_VALID)
        ->and($account->scopes)->toBe(['user.info.basic', 'video.publish']);
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'oauth/token')
        && $request['grant_type'] === 'authorization_code'
        && $request['code'] === 'auth-code'
        && $request['client_key'] === 'client-key');
});

it('rejects a callback with a forged state or a denied authorization', function (string $query, string $expected): void {
    $this->actingAs(User::factory()->create())->withSession(['oauth.tiktok.state' => 'state-1']);

    $this->get('/oauth/tiktok/callback?'.$query)
        ->assertRedirect(route('accounts.index'))
        ->assertSessionHas('error', fn (string $error): bool => str_contains($error, $expected));

    expect(SocialAccount::query()->count())->toBe(0);
    Http::assertNothingSent();
})->with([
    'forged state' => ['code=x&state=other', 'não partiu daqui'],
    'denied' => ['error=access_denied&error_description=User+denied&state=state-1', 'User denied'],
]);

it('posts SELF_ONLY on an unaudited app: creator_info, init, chunk upload and status until PUBLISH_COMPLETE', function (): void {
    fakeTiktokPosting(['SELF_ONLY'], [
        ['status' => 'PROCESSING_UPLOAD'],
        ['status' => 'PUBLISH_COMPLETE', 'publicaly_available_post_id' => []],
    ]);
    $logs = tiktokOfficialLogs();
    $post = tiktokOfficialPost(tiktokOfficialAccount());

    $this->artisan('posts:dispatch')->assertSuccessful();

    $post->refresh();
    expect($post->status)->toBe(PostStatusEnum::Published)
        ->and($post->privacy)->toBe('private')
        ->and($post->url)->toBeNull()
        ->and($post->youtubeShort->posted_tiktok_at)->not->toBeNull()
        ->and($logs())->toContain('sem auditoria')->not->toContain(TTO_ACCESS);

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), 'video/init/')
        && $request->hasHeader('Authorization', 'Bearer '.TTO_ACCESS)
        && $request['post_info']['title'] === "Corte bom\n\n#shorts"
        && $request['post_info']['privacy_level'] === 'SELF_ONLY'
        && $request['post_info']['disable_duet'] === true
        && $request['source_info'] === ['source' => 'FILE_UPLOAD', 'video_size' => 3, 'chunk_size' => 3, 'total_chunk_count' => 1]);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT'
        && $request->url() === TTO_UPLOAD
        && $request->hasHeader('Content-Range', 'bytes 0-2/3'));
    Sleep::assertSleptTimes(1);

    $discord = Http::recorded(fn (Request $request): bool => str_contains($request->url(), 'discord.test'));
    expect($discord->first()[0]->body())->not->toContain(TTO_ACCESS)->not->toContain(TTO_REFRESH);
});

it('posts PUBLIC_TO_EVERYONE on an audited app and records the video link', function (): void {
    config(['services.tiktok.app_audited' => true]);
    fakeTiktokPosting(['PUBLIC_TO_EVERYONE', 'SELF_ONLY'], [['status' => 'PUBLISH_COMPLETE', 'publicaly_available_post_id' => [7_412_345_678]]]);

    $result = resolve(TikTokPostService::class)->post(tiktokOfficialPost(tiktokOfficialAccount()), tiktokOfficialFile());

    expect($result->status)->toBe(PostStatusEnum::Published)
        ->and($result->privacy)->toBe('public')
        ->and($result->url)->toBe('https://www.tiktok.com/@clips/video/7412345678');
});

it('fails before uploading when the account does not allow the privacy level', function (): void {
    config(['services.tiktok.app_audited' => true]);
    fakeTiktokPosting(['SELF_ONLY']);

    $result = resolve(TikTokPostService::class)->post(tiktokOfficialPost(tiktokOfficialAccount()), tiktokOfficialFile());

    expect($result->status)->toBe(PostStatusEnum::Failed)
        ->and($result->error)->toContain('PUBLIC_TO_EVERYONE não permitida')->toContain('SELF_ONLY');
    Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), 'video/init/'));
});

it('refreshes an expired token and saves the rotated refresh token', function (): void {
    fakeTiktokPosting();
    $account = tiktokOfficialAccount(['token_expires_at' => now()->subMinute()]);

    $result = resolve(TikTokPostService::class)->post(tiktokOfficialPost($account), tiktokOfficialFile());

    $account->refresh();
    expect($result->status)->toBe(PostStatusEnum::Published)
        ->and($account->access_token)->toBe('act.fresh')
        ->and($account->refresh_token)->toBe('rft.fresh');
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'oauth/token')
        && $request['grant_type'] === 'refresh_token'
        && $request['refresh_token'] === TTO_REFRESH);
    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), 'creator_info/query/')
        && $request->hasHeader('Authorization', 'Bearer act.fresh'));
});

it('invalidates the account when the refresh token is dead, without leaking it', function (): void {
    Http::fake(['open.tiktokapis.com/v2/oauth/token/' => Http::response(['error' => 'invalid_grant', 'error_description' => 'Refresh token is invalid or expired.', 'log_id' => 'log-1'], 400)]);
    $logs = tiktokOfficialLogs();
    $account = tiktokOfficialAccount(['token_expires_at' => now()->subMinute()]);

    $result = resolve(TikTokPostService::class)->post(tiktokOfficialPost($account), tiktokOfficialFile());

    expect($result->status)->toBe(PostStatusEnum::Failed)
        ->and($result->error)->toContain('invalid_grant')->toContain('Revincule')->not->toContain(TTO_REFRESH)
        ->and($account->refresh()->session_status)->toBe(SocialAccount::SESSION_INVALID)
        ->and($logs())->not->toContain(TTO_REFRESH)->not->toContain(TTO_ACCESS);
    Http::assertSentCount(1);
});

it('maps API errors to readable failures and never retries', function (int $status, string $code, string $expected, string $session): void {
    Http::fake([TTO_API.'creator_info/query/' => Http::response(tiktokError($code), $status)]);
    $account = tiktokOfficialAccount();

    $result = resolve(TikTokPostService::class)->post(tiktokOfficialPost($account), tiktokOfficialFile());

    expect($result->status)->toBe(PostStatusEnum::Failed)
        ->and($result->error)->toContain($expected)
        ->and($account->refresh()->session_status)->toBe($session);
    Http::assertSentCount(1);
})->with([
    'token revoked' => [401, 'access_token_invalid', 'revincule', SocialAccount::SESSION_INVALID],
    'rate limit' => [429, 'rate_limit_exceeded', 'Cota do TikTok', SocialAccount::SESSION_VALID],
    'daily posts' => [403, 'spam_risk_too_many_posts', 'limite diário de posts', SocialAccount::SESSION_VALID],
    'unaudited public account' => [403, 'unaudited_client_can_only_post_to_private_accounts', 'só posta em conta privada', SocialAccount::SESSION_VALID],
    'other' => [400, 'invalid_params', 'TikTok recusou a consulta do criador (HTTP 400, invalid_params): Rejected.', SocialAccount::SESSION_VALID],
]);

it('fails with the reason when TikTok gives up processing the video', function (): void {
    fakeTiktokPosting(['SELF_ONLY'], [['status' => 'FAILED', 'fail_reason' => 'duration_check']]);

    $result = resolve(TikTokPostService::class)->post(tiktokOfficialPost(tiktokOfficialAccount()), tiktokOfficialFile());

    expect($result->status)->toBe(PostStatusEnum::Failed)->and($result->error)->toContain('duration_check');
});

it('fails asking to check the app when the status never settles', function (): void {
    fakeTiktokPosting(['SELF_ONLY'], array_fill(0, 120, ['status' => 'PROCESSING_DOWNLOAD']));

    $result = resolve(TikTokPostService::class)->post(tiktokOfficialPost(tiktokOfficialAccount()), tiktokOfficialFile());

    expect($result->status)->toBe(PostStatusEnum::Failed)
        ->and($result->error)->toContain('v_pub_1')->toContain('confira no app');
    Sleep::assertSleptTimes(120);
});

it('lists the official account on /contas without tokens or the cookie editor', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);
    $account = tiktokOfficialAccount(['user_id' => $user->id]);

    $component = Livewire::test(Index::class);

    expect($component->html())->toContain('API oficial (Login Kit)')->toContain('TikTok oficial')
        ->not->toContain(TTO_ACCESS)->not->toContain(TTO_REFRESH)
        ->not->toContain('editTiktok('.$account->id.')');
    expect(json_encode($component->snapshot, JSON_THROW_ON_ERROR))->not->toContain(TTO_ACCESS);
    $component->call('editTiktok', $account->id)->assertSet('showTiktokModal', false);
});
