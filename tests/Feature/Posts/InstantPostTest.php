<?php

declare(strict_types=1);

use App\Livewire\Posts\Instant;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\YoutubeShort;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function (): void {
    config()->set('microservices.tiktok_post.base_url', 'http://tiktok.test');
    config()->set('microservices.tiktok_post.callback_url', 'https://laravel.test/api/tiktok-posts/callback');
});

test('instant post page renders for authenticated users', function (): void {
    YoutubeShort::factory()->count(2)->create();

    $this->actingAs(User::factory()->create())
        ->get(route('posts.instant'))
        ->assertOk()
        ->assertSee('Postagem instantânea')
        ->assertSee('No estoque');
});

test('it selects a random stock video', function (): void {
    $short = YoutubeShort::factory()->create();

    Livewire::actingAs(User::factory()->create())
        ->test(Instant::class)
        ->call('pickRandom')
        ->assertSet('selectedShortId', $short->id)
        ->assertSet('postYoutube', true)
        ->assertSet('postTiktok', true);
});

test('it queues selected video for youtube and tiktok', function (): void {
    Queue::fake();

    SocialAccount::query()->create([
        'platform' => 'youtube',
        'name' => 'ClipsedV1s',
        'external_account_id' => 'UCNb95GjReN82jqeGar375Tg',
        'access_token' => 'token',
        'refresh_token' => 'refresh',
        'token_expires_at' => now()->addHour(),
        'scopes' => [
            'https://www.googleapis.com/auth/youtube.upload',
            'https://www.googleapis.com/auth/youtube.readonly',
        ],
        'is_active' => true,
    ]);

    $short = YoutubeShort::factory()->create([
        'youtube_id' => 'abc123',
        'title' => 'Video teste',
        'hashtags' => ['#shorts'],
        'video_path' => 'shorts/abc123.mp4',
    ]);

    Http::fake([
        'http://tiktok.test/posts' => Http::response(['job_id' => 'job-instant', 'status' => 'queued'], 202),
    ]);

    Livewire::actingAs(User::factory()->create())
        ->test(Instant::class)
        ->set('selectedShortId', $short->id)
        ->set('postYoutube', true)
        ->set('postTiktok', true)
        ->call('postSelected')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('tiktok_posts', [
        'uuid' => 'job-instant',
        'youtube_id' => 'abc123',
        'status' => 'queued',
    ]);

    Http::assertSent(fn (Request $request): bool => $request->url() === 'http://tiktok.test/posts'
        && $request['video_id'] === 'abc123'
        && $request['webhook_url'] === 'https://laravel.test/api/tiktok-posts/callback'
        && $request['title'] === 'Video teste'
        && $request['hashtags'] === ['#shorts']);
});
