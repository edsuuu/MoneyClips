<?php

declare(strict_types=1);

use App\Actions\Fortify\ResetUserPassword;
use App\Livewire\Settings\Profile;
use App\Livewire\Settings\Security;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

it('lets a google user create the first password without the current one', function (): void {
    $user = User::factory()->create(['has_password' => false, 'google_id' => 'g-1']);

    Livewire::actingAs($user)
        ->test(Security::class)
        ->assertSet('hasPassword', false)
        ->set('password', 'S3nha-Forte!123')
        ->set('password_confirmation', 'S3nha-Forte!123')
        ->call('updatePassword')
        ->assertHasNoErrors()
        ->assertSet('hasPassword', true);

    expect($user->fresh()?->has_password)->toBeTrue();
    expect(Hash::check('S3nha-Forte!123', (string) $user->fresh()?->password))->toBeTrue();
});

it('flips the flag when the password is reset by email', function (): void {
    $user = User::factory()->create(['has_password' => false, 'google_id' => 'g-2']);

    resolve(ResetUserPassword::class)->reset($user, ['password' => 'S3nha-Forte!123', 'password_confirmation' => 'S3nha-Forte!123']);

    expect($user->fresh()?->has_password)->toBeTrue();
});

it('still requires the current password once the user has one', function (): void {
    $user = User::factory()->create(['password' => 'senha-antiga']);

    Livewire::actingAs($user)
        ->test(Security::class)
        ->assertSet('hasPassword', true)
        ->set('current_password', 'errada')
        ->set('password', 'S3nha-Forte!123')
        ->set('password_confirmation', 'S3nha-Forte!123')
        ->call('updatePassword')
        ->assertHasErrors('current_password');
});

it('lists active sessions and closes another one but never the current', function (): void {
    config()->set('session.driver', 'database');

    $user = User::factory()->create();

    DB::table('sessions')->insert([
        ['id' => 'other-session', 'user_id' => $user->id, 'ip_address' => '10.0.0.1', 'user_agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) Chrome/120.0', 'payload' => '', 'last_activity' => time()],
    ]);

    $component = Livewire::actingAs($user)->test(Security::class);

    expect($component->instance()->sessions())->toHaveCount(1);

    $component->call('logoutSession', 'other-session');

    expect(DB::table('sessions')->where('id', 'other-session')->exists())->toBeFalse();
});

it('never updates the email from the profile screen', function (): void {
    $user = User::factory()->create(['email' => 'fixo@moneyclips.test']);

    Livewire::actingAs($user)
        ->test(Profile::class)
        ->set('name', 'Novo Nome')
        ->set('email', 'invasor@evil.test')
        ->call('updateProfileInformation')
        ->assertHasNoErrors();

    expect($user->fresh()?->email)->toBe('fixo@moneyclips.test')
        ->and($user->fresh()?->name)->toBe('Novo Nome');
});
