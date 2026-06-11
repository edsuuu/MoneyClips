<?php

declare(strict_types=1);

use App\Jobs\PostYoutubeShortJob;
use App\Models\SocialAccount;
use App\Models\YoutubeShort;
use App\Services\DiscordNotifier;
use App\Services\Youtube\ShortsPoster;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    config()->set('youtube_shorts.disk', 'local');
    config()->set('youtube_shorts.discord_webhook', '');
    Storage::fake('local');
});

function makeYoutubeAccount(): SocialAccount
{
    return SocialAccount::query()->create([
        'platform' => 'youtube',
        'name' => 'Canal de Teste',
        'external_account_id' => 'UC123',
        'access_token' => 'fake-access-token',
        'refresh_token' => 'fake-refresh-token',
        'token_expires_at' => now()->addHour(),
        'is_active' => true,
    ]);
}

test('posts an available short and marks it as posted', function (): void {
    makeYoutubeAccount();

    $short = YoutubeShort::factory()->create();
    Storage::disk('local')->put((string) $short->video_path, 'fake-video-bytes');

    Http::fake([
        'https://www.googleapis.com/upload/youtube/v3/videos*' => Http::response('', 200, [
            'Location' => 'https://upload.example/session-1',
        ]),
        'https://upload.example/session-1' => Http::response(['id' => 'novo-video-id'], 200),
    ]);

    new PostYoutubeShortJob($short->id)->handle(
        resolve(ShortsPoster::class),
        resolve(DiscordNotifier::class),
    );

    $short->refresh();

    expect($short->posted_at)->not->toBeNull();
    expect($short->youtube_video_id)->toBe('novo-video-id');
});

test('skips a short that was already posted', function (): void {
    makeYoutubeAccount();

    $short = YoutubeShort::factory()->posted()->create();
    $originalVideoId = $short->youtube_video_id;

    Http::fake();

    new PostYoutubeShortJob($short->id)->handle(
        resolve(ShortsPoster::class),
        resolve(DiscordNotifier::class),
    );

    Http::assertNothingSent();
    expect($short->refresh()->youtube_video_id)->toBe($originalVideoId);
});

test('fails clearly when no youtube account is connected', function (): void {
    $short = YoutubeShort::factory()->create();
    Storage::disk('local')->put((string) $short->video_path, 'fake-video-bytes');

    Http::fake();

    expect(fn () => new PostYoutubeShortJob($short->id)->handle(
        resolve(ShortsPoster::class),
        resolve(DiscordNotifier::class),
    ))->toThrow(RuntimeException::class, 'Nenhuma conta do YouTube conectada');
});
