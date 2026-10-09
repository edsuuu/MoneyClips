<?php

declare(strict_types=1);

use App\Enums\PostStatusEnum;
use App\Livewire\Accounts\Index;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\User;
use App\Models\YoutubeShort;
use App\Services\API\Youtube\YoutubePostService;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Livewire\Livewire;

const YT_ACCESS = 'ya29.secret-access-token';

const YT_REFRESH = '1//secret-refresh-token';

const YT_SESSION = 'https://www.googleapis.com/upload/youtube/v3/videos?uploadType=resumable&upload_id=session-1';

/**
 * @param  array<string, mixed>  $attributes
 */
function youtubeAccount(array $attributes = []): SocialAccount
{
    return SocialAccount::query()->create([
        'platform' => 'youtube',
        'name' => 'Canal',
        'access_token' => YT_ACCESS,
        'refresh_token' => YT_REFRESH,
        'token_expires_at' => now()->addHour(),
        'meta' => ['channel_id' => 'UC1', 'privacy_status' => 'public'],
        ...$attributes,
    ]);
}

function youtubePost(SocialAccount $account): SocialPost
{
    $short = YoutubeShort::factory()->create(['title' => 'Meu corte <bom> #podcast', 'hashtags' => ['#shorts', '#podcast', 'cortes']]);
    Storage::disk('s3')->put($short->video_path, 'mp4');

    return SocialPost::query()->create([
        'youtube_short_id' => $short->id,
        'social_account_id' => $account->id,
        'scheduled_for' => now(),
        'status' => PostStatusEnum::Scheduled,
    ]);
}

function youtubeTempFile(int $bytes = 4): string
{
    $path = (string) tempnam(sys_get_temp_dir(), 'yt-test-');
    file_put_contents($path, random_bytes($bytes));

    return $path;
}

/**
 * @param  array<string, mixed>  $video
 */
function fakeYoutubeUpload(array $video = ['id' => 'vid123', 'status' => ['privacyStatus' => 'private']]): void
{
    Http::fake([
        'oauth2.googleapis.com/token' => Http::response(['access_token' => 'ya29.fresh-access', 'expires_in' => 3599]),
        'www.googleapis.com/upload/youtube/v3/videos*' => fn (Request $request) => $request->method() === 'POST'
            ? Http::response('', 200, ['Location' => YT_SESSION])
            : Http::response($video),
        'discord.test/*' => Http::response(),
    ]);
}

/**
 * @return Closure(): string
 */
function captureLogs(): Closure
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
    config(['services.google.client_id' => 'client-id', 'services.google.client_secret' => 'client-secret']);
});

it('uploads resumable with title, description and hashtags and forces private on an unverified project', function (): void {
    fakeYoutubeUpload();
    $logs = captureLogs();
    $post = youtubePost(youtubeAccount());

    $result = resolve(YoutubePostService::class)->post($post, youtubeTempFile());

    expect($result->status)->toBe(PostStatusEnum::Published)
        ->and($result->url)->toBe('https://www.youtube.com/shorts/vid123')
        ->and($result->privacy)->toBe('private')
        ->and($logs())->toContain('Projeto Google não verificado');

    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'oauth2.googleapis.com'));
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->hasHeader('Authorization', 'Bearer '.YT_ACCESS)
        && $request->hasHeader('X-Upload-Content-Length', '4')
        && $request['snippet']['title'] === 'Meu corte bom #podcast'
        && $request['snippet']['description'] === "Meu corte bom #podcast\n\n#shorts #cortes"
        && $request['snippet']['tags'] === ['shorts', 'podcast', 'cortes']
        && $request['status']['privacyStatus'] === 'private');
    Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT'
        && $request->url() === YT_SESSION
        && $request->hasHeader('Content-Range', 'bytes 0-3/4'));
});

it('keeps the wanted privacy when the Google project is verified', function (): void {
    config(['services.google.youtube_app_verified' => true]);
    fakeYoutubeUpload(['id' => 'vid123', 'status' => ['privacyStatus' => 'public']]);

    $result = resolve(YoutubePostService::class)->post(youtubePost(youtubeAccount()), youtubeTempFile());

    expect($result->privacy)->toBe('public');
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' && $request['status']['privacyStatus'] === 'public');
});

it('follows the Range of a 308 to send the next chunk', function (): void {
    $size = 9 * 1024 * 1024;
    Http::fake([
        'www.googleapis.com/upload/youtube/v3/videos*' => Http::sequence()
            ->push('', 200, ['Location' => YT_SESSION])
            ->push('', 308, ['Range' => 'bytes=0-8388607'])
            ->push(['id' => 'vid123', 'status' => ['privacyStatus' => 'private']]),
    ]);

    $result = resolve(YoutubePostService::class)->post(youtubePost(youtubeAccount()), youtubeTempFile($size));

    expect($result->status)->toBe(PostStatusEnum::Published);
    Http::assertSent(fn (Request $request): bool => $request->hasHeader('Content-Range', sprintf('bytes 0-8388607/%d', $size)));
    Http::assertSent(fn (Request $request): bool => $request->hasHeader('Content-Range', sprintf('bytes 8388608-%d/%d', $size - 1, $size))
        && mb_strlen($request->body(), '8bit') === $size - 8388608);
});

it('resumes from the Range the session reports after a 5xx in the middle of the upload', function (): void {
    $size = 9 * 1024 * 1024;
    Http::fake([
        'www.googleapis.com/upload/youtube/v3/videos*' => Http::sequence()
            ->push('', 200, ['Location' => YT_SESSION])
            ->push('', 503)
            ->push('', 308, ['Range' => 'bytes=0-4194303'])
            ->push(['id' => 'vid123', 'status' => ['privacyStatus' => 'private']]),
    ]);

    $result = resolve(YoutubePostService::class)->post(youtubePost(youtubeAccount()), youtubeTempFile($size));

    expect($result->status)->toBe(PostStatusEnum::Published)
        ->and($result->url)->toBe('https://www.youtube.com/shorts/vid123');
    Http::assertSent(fn (Request $request): bool => $request->hasHeader('Content-Range', sprintf('bytes */%d', $size))
        && $request->body() === '');
    Http::assertSent(fn (Request $request): bool => $request->hasHeader('Content-Range', sprintf('bytes 4194304-%d/%d', $size - 1, $size))
        && mb_strlen($request->body(), '8bit') === $size - 4194304);
    Sleep::assertSleptTimes(1);
});

it('publishes when the connection drops on the last chunk but the session says the video was created', function (): void {
    Http::fake([
        'www.googleapis.com/upload/youtube/v3/videos*' => Http::sequence()
            ->push('', 200, ['Location' => YT_SESSION])
            ->pushFailedConnection()
            ->push(['id' => 'vid123', 'status' => ['privacyStatus' => 'private']], 201),
    ]);

    $result = resolve(YoutubePostService::class)->post(youtubePost(youtubeAccount()), youtubeTempFile());

    expect($result->status)->toBe(PostStatusEnum::Published)
        ->and($result->url)->toBe('https://www.youtube.com/shorts/vid123');
    Http::assertSent(fn (Request $request): bool => $request->hasHeader('Content-Range', 'bytes */4'));
});

it('fails after three unanswered session queries, saying whether the video may exist', function (int $size, string $expected): void {
    Http::fake([
        'www.googleapis.com/upload/youtube/v3/videos*' => Http::sequence()
            ->push('', 200, ['Location' => YT_SESSION])
            ->push('', 503)
            ->push('', 503)
            ->pushFailedConnection()
            ->push('', 503),
    ]);

    $result = resolve(YoutubePostService::class)->post(youtubePost(youtubeAccount()), youtubeTempFile($size));

    expect($result->status)->toBe(PostStatusEnum::Failed)
        ->and($result->error)->toContain('3 tentativas')->toContain($expected);
    Http::assertSentCount(5);
})->with([
    'last chunk' => [4, 'confira no YouTube Studio'],
    'middle chunk' => [9 * 1024 * 1024, 'O vídeo não foi criado'],
]);

it('skips a tag over the 500-char budget without spending the budget on it', function (): void {
    fakeYoutubeUpload();
    $post = youtubePost(youtubeAccount());
    $post->youtubeShort->update(['hashtags' => [str_repeat('a', 495), str_repeat('b', 10), 'ok']]);

    resolve(YoutubePostService::class)->post($post, youtubeTempFile());

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request['snippet']['tags'] === [str_repeat('a', 495), 'ok']);
});

it('refreshes an expired token, saves it and uploads with the new one', function (): void {
    fakeYoutubeUpload();
    $account = youtubeAccount(['token_expires_at' => now()->subMinute()]);

    $result = resolve(YoutubePostService::class)->post(youtubePost($account), youtubeTempFile());

    $account->refresh();
    expect($result->status)->toBe(PostStatusEnum::Published)
        ->and($account->access_token)->toBe('ya29.fresh-access')
        ->and($account->refresh_token)->toBe(YT_REFRESH)
        ->and($account->token_expires_at?->toDateTimeString())->toBe('2026-10-08 20:59:59');
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'oauth2.googleapis.com/token')
        && $request['grant_type'] === 'refresh_token'
        && $request['refresh_token'] === YT_REFRESH);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && str_contains($request->url(), 'upload/youtube')
        && $request->hasHeader('Authorization', 'Bearer ya29.fresh-access'));
});

it('fails, invalidates the account and leaks no secret when the refresh token was revoked', function (): void {
    Http::fake(['oauth2.googleapis.com/token' => Http::response(['error' => 'invalid_grant', 'error_description' => 'Token has been expired or revoked.'], 400)]);
    $logs = captureLogs();
    $account = youtubeAccount(['token_expires_at' => now()->subMinute()]);

    $result = resolve(YoutubePostService::class)->post(youtubePost($account), youtubeTempFile());

    expect($result->status)->toBe(PostStatusEnum::Failed)
        ->and($result->error)->toContain('invalid_grant')->toContain('revincule')
        ->and($result->error)->not->toContain(YT_REFRESH)
        ->and($account->refresh()->session_status)->toBe(SocialAccount::SESSION_INVALID)
        ->and($logs())->not->toContain(YT_REFRESH)->not->toContain(YT_ACCESS);
    Http::assertSentCount(1);
});

it('fails on exhausted quota without retrying', function (): void {
    Http::fake(['www.googleapis.com/upload/youtube/v3/videos*' => Http::response([
        'error' => ['code' => 403, 'message' => 'The request cannot be completed because you have exceeded your quota.', 'errors' => [['reason' => 'quotaExceeded']]],
    ], 403)]);

    $result = resolve(YoutubePostService::class)->post(youtubePost(youtubeAccount()), youtubeTempFile());

    expect($result->status)->toBe(PostStatusEnum::Failed)
        ->and($result->error)->toContain('Cota da YouTube Data API esgotada (quotaExceeded)');
    Http::assertSentCount(1);
});

it('fails with the API reason on a rejected upload and invalidates on 401', function (int $status, string $reason, string $expected, string $session): void {
    Http::fake(['www.googleapis.com/upload/youtube/v3/videos*' => Http::response([
        'error' => ['code' => $status, 'message' => 'Rejected <b>here</b>.', 'errors' => [['reason' => $reason]]],
    ], $status)]);
    $account = youtubeAccount(['session_status' => SocialAccount::SESSION_VALID]);

    $result = resolve(YoutubePostService::class)->post(youtubePost($account), youtubeTempFile());

    expect($result->status)->toBe(PostStatusEnum::Failed)
        ->and($result->error)->toContain($expected)
        ->and($account->refresh()->session_status)->toBe($session);
})->with([
    'invalid title' => [400, 'invalidTitle', 'YouTube recusou a abertura do upload (HTTP 400, invalidTitle): Rejected here.', SocialAccount::SESSION_VALID],
    'upload limit' => [400, 'uploadLimitExceeded', 'limite de uploads', SocialAccount::SESSION_VALID],
    'token refused' => [401, 'authError', 'revincule', SocialAccount::SESSION_INVALID],
]);

it('publishes end to end from posts:dispatch with link and posted_youtube_at, without secrets in Discord', function (): void {
    config(['services.discord.webhook' => 'https://discord.test/webhook']);
    fakeYoutubeUpload();
    $account = youtubeAccount(['token_expires_at' => now()->subMinute()]);
    $post = youtubePost($account);

    $this->artisan('posts:dispatch')->assertSuccessful();

    $post->refresh();
    expect($post->status)->toBe(PostStatusEnum::Published)
        ->and($post->url)->toBe('https://www.youtube.com/shorts/vid123')
        ->and($post->privacy)->toBe('private')
        ->and($post->youtubeShort->posted_youtube_at)->not->toBeNull();

    $discord = Http::recorded(fn (Request $request): bool => str_contains($request->url(), 'discord.test'));
    expect($discord)->toHaveCount(1)
        ->and($discord->first()[0]->body())->not->toContain(YT_ACCESS)->not->toContain(YT_REFRESH)->not->toContain('ya29.fresh-access');
});

it('shows a revoked YouTube account on /contas without rendering its tokens', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);
    youtubeAccount(['user_id' => $user->id, 'session_status' => SocialAccount::SESSION_INVALID]);

    $html = Livewire::test(Index::class)->call('openYoutube')->html();

    expect($html)->toContain('Acesso revogado')
        ->not->toContain(YT_ACCESS)
        ->not->toContain(YT_REFRESH);
});
