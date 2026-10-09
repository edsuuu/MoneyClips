<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\YoutubeShort;
use Illuminate\Support\Facades\Config;

beforeEach(function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);
    YoutubeShort::factory()->for($user)->create(['ready_at' => null]);
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

it('leaves the tour out of screens without steps', function (): void {
    $this->get('/dashboard')
        ->assertOk()
        ->assertDontSee('guidedTour(', false)
        ->assertDontSee('Ver tutorial');
});
