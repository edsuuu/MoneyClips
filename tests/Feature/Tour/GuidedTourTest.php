<?php

declare(strict_types=1);

use App\Enums\PostStatusEnum;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\User;
use App\Models\YoutubeShort;
use Illuminate\Support\Facades\Config;

beforeEach(function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);
    YoutubeShort::factory()->for($user)->create(['ready_at' => null]);
    $ready = YoutubeShort::factory()->for($user)->ready()->create();
    $account = SocialAccount::query()->create(['user_id' => $user->id, 'platform' => 'tiktok', 'name' => '@conta', 'is_active' => true]);
    SocialAccount::query()->create(['user_id' => $user->id, 'platform' => 'youtube', 'name' => 'Canal', 'is_active' => true]);
    SocialPost::query()->create([
        'youtube_short_id' => $ready->id,
        'social_account_id' => $account->id,
        'scheduled_for' => now()->addDay(),
        'status' => PostStatusEnum::Scheduled,
    ]);
});

it('mounts the tour with every step anchored on the screen', function (string $url, string $routeName): void {
    $response = $this->get($url)
        ->assertOk()
        ->assertSee('guidedTour(', false)
        ->assertSee('Ver tutorial');

    foreach (Config::array('tour')[$routeName] as $step) {
        $response->assertSee('data-tour="'.$step['target'].'"', false);
    }
})->with([
    'upload' => ['/upload', 'uploads.create'],
    'stock' => ['/meus-videos', 'videos.index'],
]);

it('walks the stock tour through the state lines of each platform', function (): void {
    expect(array_column(Config::array('tour')['videos.index'], 'target'))->toContain('video-posts');

    $this->get('/meus-videos')->assertSee('data-tour="video-posts"', false);
});

it('leaves the tour out of screens without steps', function (): void {
    $this->get('/dashboard')
        ->assertOk()
        ->assertDontSee('guidedTour(', false)
        ->assertDontSee('Ver tutorial');
});
