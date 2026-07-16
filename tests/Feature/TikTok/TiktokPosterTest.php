<?php

declare(strict_types=1);

use App\Models\ScheduleSlot;
use App\Models\SocialAccount;
use App\Models\YoutubeShort;
use App\Services\AutoPost\PostTaskData;
use App\Services\TikTokUploader\TiktokPosterService;
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

function tiktokTask(): PostTaskData
{
    Storage::fake('s3');
    $short = YoutubeShort::factory()->ready()->create();
    Storage::disk('s3')->put($short->video_path, 'video-bytes');

    return PostTaskData::fromShort($short, ScheduleSlot::factory()->dispatched()->create(['youtube_short_id' => $short->id]));
}

it('queues the post sending the binary, cookies and webhook_url via multipart', function (): void {
    tiktokAccount();
    Http::fake(['*/posts' => Http::response(['job_id' => 'job-123', 'status' => 'queued'], 202)]);

    $result = resolve(TiktokPosterService::class)->post(tiktokTask());

    expect($result->outcome)->toBe('queued')
        ->and($result->externalId)->toBe('job-123');

    Http::assertSent(function ($request): bool {
        $parts = collect($request->data());
        $field = fn (string $name) => $parts->first(fn (array $p): bool => ($p['name'] ?? '') === $name);

        return str_contains((string) $request->url(), '/posts')
            && $field('video') !== null
            && is_string($field('cookies')['contents'] ?? null)
            && str_contains($field('cookies')['contents'], 'sessionid')
            && str_contains((string) ($field('webhook_url')['contents'] ?? ''), '/api/tiktok-posts/webhook');
    });
});

it('fails when the uploader refuses the job', function (): void {
    tiktokAccount();
    Http::fake(['*/posts' => Http::response(['detail' => 'erro interno'], 500)]);

    $result = resolve(TiktokPosterService::class)->post(tiktokTask());

    expect($result->outcome)->toBe('failed')
        ->and($result->error)->toContain('500');
});

it('fails when the 202 comes without a job_id', function (): void {
    tiktokAccount();
    Http::fake(['*/posts' => Http::response(['status' => 'queued'], 202)]);

    expect(resolve(TiktokPosterService::class)->post(tiktokTask())->outcome)->toBe('failed');
});

it('short-circuits when the session is marked invalid', function (): void {
    tiktokAccount(['session_status' => SocialAccount::SESSION_INVALID]);
    Http::fake();

    $result = resolve(TiktokPosterService::class)->post(tiktokTask());

    expect($result->outcome)->toBe('failed')
        ->and($result->error)->toContain('renove os cookies');
    Http::assertNothingSent();
});

it('fails with clear guidance when there is no account or no cookies', function (): void {
    $task = tiktokTask();

    expect(resolve(TiktokPosterService::class)->post($task)->error)->toContain('Nenhuma conta TikTok');

    tiktokAccount(['cookies' => []]);
    expect(resolve(TiktokPosterService::class)->post($task)->error)->toContain('sem cookies');
});
