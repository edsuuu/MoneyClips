<?php

declare(strict_types=1);

use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\User;
use App\Models\YoutubeShort;
use Illuminate\Support\Facades\Http;

it('records restricted TikTok callbacks without marking the short as posted', function (): void {
    Http::fake();

    $user = User::factory()->create();
    $short = YoutubeShort::factory()->create([
        'youtube_id' => 'abc123',
        'title' => 'Restricted short',
        'video_path' => 'shorts/abc123/short_abc123.mp4',
        'posted_tiktok_at' => null,
    ]);

    $account = SocialAccount::query()->create([
        'user_id' => $user->id,
        'platform' => 'tiktok',
        'name' => 'clipsd211',
        'is_active' => true,
        'session_status' => SocialAccount::SESSION_UNKNOWN,
        'cookies' => [['name' => 'sessionid', 'value' => 'secret', 'domain' => '.tiktok.com', 'path' => '/']],
    ]);

    $response = $this->postJson(route('tiktok-posts.callback'), [
        'job_id' => '11111111-1111-4111-8111-111111111111',
        'video_id' => 'abc123',
        'status' => 'restricted',
        'session_valid' => true,
        'login_failed' => false,
        'title' => 'Restricted short',
        'error' => 'Vídeo restringido pelo TikTok: Content may be restricted',
        'finished_at' => '2026-07-08T12:00:00Z',
        'session_status' => 'valid',
    ]);

    $response->assertOk()->assertJson(['ok' => true]);

    $post = SocialPost::query()
        ->where('uuid', '11111111-1111-4111-8111-111111111111')
        ->firstOrFail();

    expect($short->refresh()->posted_tiktok_at)->toBeNull()
        ->and($account->refresh()->session_status)->toBe(SocialAccount::SESSION_VALID)
        ->and($post->status)->toBe('restricted')
        ->and($post->error)->toContain('Content may be restricted');
});
