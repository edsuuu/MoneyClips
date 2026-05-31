<?php

declare(strict_types=1);

use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\AbstractProvider;
use Laravel\Socialite\Two\User as SocialiteUser;

test('youtube oauth callback links the channel to the authenticated user', function (): void {
    Config::set('services.google.client_id', 'google-client-id');
    Config::set('services.google.client_secret', 'google-client-secret');
    Config::set('services.google.redirect', 'http://localhost/oauth/youtube/callback');
    Config::set('social-publishing.account_owner_id', 1);

    $user = User::factory()->create();
    $this->actingAs($user);

    Http::fake([
        'https://www.googleapis.com/youtube/v3/channels*' => Http::response(['items' => []]),
    ]);

    $provider = Mockery::mock(AbstractProvider::class);
    $socialUser = Mockery::mock(SocialiteUser::class);
    $socialUser->shouldReceive('getName')->andReturn('Canal do usuario');
    $socialUser->shouldReceive('getNickname')->andReturnNull();
    $socialUser->shouldReceive('getId')->andReturn('google-channel-owner');
    $socialUser->token = 'youtube-access-token';
    $socialUser->refreshToken = 'youtube-refresh-token';
    $socialUser->expiresIn = 3600;

    $provider->shouldReceive('user')->once()->andReturn($socialUser);
    Socialite::shouldReceive('driver')->once()->with('google')->andReturn($provider);

    $this->get(route('oauth.callback', ['platform' => 'youtube']))
        ->assertRedirect(route('social-accounts'))
        ->assertSessionHas('status', 'Conta(s) conectada(s): Canal do usuario');

    $account = SocialAccount::query()->where('platform', 'youtube')->sole();

    expect($account->user_id)->toBe($user->id);
    expect($account->external_account_id)->toBe('google-channel-owner');
});
