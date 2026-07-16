<?php

declare(strict_types=1);

use App\Models\ScheduleSlot;
use App\Models\SocialAccount;
use App\Models\YoutubeShort;
use App\Services\AutoPost\Posters\PostTask;
use App\Services\AutoPost\Posters\TiktokPoster;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

function tiktokAccount(array $attributes = []): SocialAccount
{
    return SocialAccount::query()->create([
        'platform' => 'tiktok',
        'name' => 'conta-teste',
        'is_active' => true,
        'cookies' => [['name' => 'sessionid', 'value' => 'abc', 'domain' => '.tiktok.com']],
        'session_status' => SocialAccount::SESSION_VALID,
        ...$attributes,
    ]);
}

function tiktokTask(): PostTask
{
    Storage::fake('s3');
    $short = YoutubeShort::factory()->ready()->create();
    Storage::disk('s3')->put($short->video_path, 'video-bytes');

    return PostTask::fromShort($short, ScheduleSlot::factory()->dispatched()->create(['youtube_short_id' => $short->id]));
}

it('posts the video binary via multipart and maps completed', function (): void {
    $account = tiktokAccount(['session_status' => SocialAccount::SESSION_UNKNOWN]);
    Http::fake(['*/posts' => Http::response(['status' => 'completed', 'title' => 'x'])]);

    $result = resolve(TiktokPoster::class)->post(tiktokTask());

    expect($result->outcome)->toBe('ok')
        ->and($account->refresh()->session_status)->toBe(SocialAccount::SESSION_VALID)
        ->and($account->cookies_last_validated_at)->not->toBeNull();

    Http::assertSent(function ($request): bool {
        $parts = collect($request->data());
        $field = fn (string $name) => $parts->first(fn (array $p): bool => ($p['name'] ?? '') === $name);

        return str_contains((string) $request->url(), '/posts')
            && $field('video') !== null
            && is_string($field('cookies')['contents'] ?? null)
            && str_contains($field('cookies')['contents'], 'sessionid');
    });
});

it('maps the dry-run outcome', function (): void {
    tiktokAccount();
    Http::fake(['*/posts' => Http::response(['status' => 'dry-run'])]);

    expect(resolve(TiktokPoster::class)->post(tiktokTask())->outcome)->toBe('dry-run');
});

it('maps the restricted outcome with the moderation detail', function (): void {
    tiktokAccount();
    Http::fake(['*/posts' => Http::response(['status' => 'restricted', 'detail' => 'moderação'])]);

    $result = resolve(TiktokPoster::class)->post(tiktokTask());

    expect($result->outcome)->toBe('restricted')
        ->and($result->error)->toBe('moderação');
});

it('marks the account invalid on 401 and short-circuits afterwards', function (): void {
    $account = tiktokAccount();
    Http::fake(['*/posts' => Http::response(['detail' => 'cookies inválidos'], 401)]);

    $result = resolve(TiktokPoster::class)->post(tiktokTask());
    expect($result->outcome)->toBe('failed')
        ->and($result->error)->toContain('Sessão inválida')
        ->and($account->refresh()->session_status)->toBe(SocialAccount::SESSION_INVALID);

    // Próximo post curto-circuita sem chamar o uploader.
    Http::fake();
    $second = resolve(TiktokPoster::class)->post(tiktokTask());
    expect($second->outcome)->toBe('failed')
        ->and($second->error)->toContain('renove os cookies');
    Http::assertNothingSent();
});

it('fails with clear guidance when there is no account or no cookies', function (): void {
    $task = tiktokTask();

    expect(resolve(TiktokPoster::class)->post($task)->error)->toContain('Nenhuma conta TikTok');

    tiktokAccount(['cookies' => []]);
    expect(resolve(TiktokPoster::class)->post($task)->error)->toContain('sem cookies');
});

it('reports server errors as failed without touching session status', function (): void {
    $account = tiktokAccount();
    Http::fake(['*/posts' => Http::response(['detail' => 'erro interno'], 500)]);

    $result = resolve(TiktokPoster::class)->post(tiktokTask());

    expect($result->outcome)->toBe('failed')
        ->and($result->error)->toContain('500')
        ->and($account->refresh()->session_status)->toBe(SocialAccount::SESSION_VALID);
});
