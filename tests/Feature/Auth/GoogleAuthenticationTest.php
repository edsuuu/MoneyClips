<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Config;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\AbstractProvider;
use Laravel\Socialite\Two\User as SocialiteUser;

test('login and registration screens show the google auth button', function (): void {
    $this->get(route('login'))
        ->assertOk()
        ->assertSee('Continue with Google');

    $this->get(route('register'))
        ->assertOk()
        ->assertSee('Continue with Google');
});

test('users can authenticate with google and a local account is created', function (): void {
    Config::set('services.google_auth.client_id', 'google-client-id');
    Config::set('services.google_auth.client_secret', 'google-client-secret');
    Config::set('services.google_auth.redirect', 'http://localhost/auth/google/callback');

    $provider = Mockery::mock(AbstractProvider::class);
    $provider->shouldReceive('scopes')->once()->with(['openid', 'profile', 'email'])->andReturnSelf();

    $socialUser = Mockery::mock(SocialiteUser::class);
    $socialUser->shouldReceive('getEmail')->andReturn('google-user@example.com');
    $socialUser->shouldReceive('getId')->andReturn('google-123');
    $socialUser->shouldReceive('getName')->andReturn('Google User');
    $socialUser->shouldReceive('getNickname')->andReturnNull();
    $socialUser->shouldReceive('getAvatar')->andReturn('https://example.com/avatar.png');

    $provider->shouldReceive('user')->once()->andReturn($socialUser);

    Socialite::shouldReceive('buildProvider')->once()->andReturn($provider);

    $this->get(route('auth.google.callback'))
        ->assertRedirect(route('dashboard'));

    $this->assertAuthenticated();

    $user = User::query()->where('email', 'google-user@example.com')->first();

    expect($user)->not()->toBeNull();
    expect($user?->google_id)->toBe('google-123');
    expect($user?->google_avatar)->toBe('https://example.com/avatar.png');
    expect($user?->email_verified_at)->not()->toBeNull();
});

test('existing users are linked to google by email', function (): void {
    Config::set('services.google_auth.client_id', 'google-client-id');
    Config::set('services.google_auth.client_secret', 'google-client-secret');
    Config::set('services.google_auth.redirect', 'http://localhost/auth/google/callback');

    $user = User::factory()->unverified()->create([
        'email' => 'existing@example.com',
        'google_id' => null,
        'google_avatar' => null,
    ]);

    $provider = Mockery::mock(AbstractProvider::class);
    $provider->shouldReceive('scopes')->once()->with(['openid', 'profile', 'email'])->andReturnSelf();

    $socialUser = Mockery::mock(SocialiteUser::class);
    $socialUser->shouldReceive('getEmail')->andReturn('existing@example.com');
    $socialUser->shouldReceive('getId')->andReturn('google-existing');
    $socialUser->shouldReceive('getName')->andReturn('Existing User');
    $socialUser->shouldReceive('getNickname')->andReturnNull();
    $socialUser->shouldReceive('getAvatar')->andReturn('https://example.com/existing.png');

    $provider->shouldReceive('user')->once()->andReturn($socialUser);

    Socialite::shouldReceive('buildProvider')->once()->andReturn($provider);

    $this->get(route('auth.google.callback'))
        ->assertRedirect(route('dashboard'));

    $user->refresh();

    expect($user->google_id)->toBe('google-existing');
    expect($user->google_avatar)->toBe('https://example.com/existing.png');
    expect($user->email_verified_at)->not()->toBeNull();
});

test('legacy google callback route remains supported', function (): void {
    Config::set('services.google_auth.client_id', 'google-client-id');
    Config::set('services.google_auth.client_secret', 'google-client-secret');
    Config::set('services.google_auth.redirect', 'http://127.0.0.1:8000/oauth2/google/callback');

    $provider = Mockery::mock(AbstractProvider::class);
    $provider->shouldReceive('scopes')->once()->with(['openid', 'profile', 'email'])->andReturnSelf();

    $socialUser = Mockery::mock(SocialiteUser::class);
    $socialUser->shouldReceive('getEmail')->andReturn('legacy-google-user@example.com');
    $socialUser->shouldReceive('getId')->andReturn('google-legacy-123');
    $socialUser->shouldReceive('getName')->andReturn('Legacy Google User');
    $socialUser->shouldReceive('getNickname')->andReturnNull();
    $socialUser->shouldReceive('getAvatar')->andReturn('https://example.com/legacy-avatar.png');

    $provider->shouldReceive('user')->once()->andReturn($socialUser);

    Socialite::shouldReceive('buildProvider')->once()->andReturn($provider);

    $this->get(route('auth.google.callback.legacy'))
        ->assertRedirect(route('dashboard'));

    $this->assertAuthenticated();

    expect(User::query()->where('email', 'legacy-google-user@example.com')->exists())->toBeTrue();
});
