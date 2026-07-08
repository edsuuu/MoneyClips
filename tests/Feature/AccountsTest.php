<?php

declare(strict_types=1);

use App\Livewire\Accounts\Index;
use App\Models\SocialAccount;
use App\Models\User;
use Livewire\Livewire;

it('cria uma conta tiktok com email e senha', function (): void {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(Index::class)
        ->call('create')
        ->set('name', 'clipsd211')
        ->set('login_email', 'conta@ex.com')
        ->set('login_password', 's3nha')
        ->set('is_active', true)
        ->call('save')
        ->assertHasNoErrors();

    $account = SocialAccount::query()
        ->where('user_id', $user->id)
        ->where('platform', 'tiktok')
        ->first();

    expect($account)->not->toBeNull()
        ->and($account->name)->toBe('clipsd211')
        ->and($account->login_email)->toBe('conta@ex.com')
        // ponytail: senha em texto puro nesta fase — se virar 'encrypted', ajustar aqui.
        ->and($account->login_password)->toBe('s3nha');
});

it('exige nome, email e senha', function (): void {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(Index::class)
        ->call('create')
        ->call('save')
        ->assertHasErrors(['name', 'login_email', 'login_password']);
});

it('remove uma conta tiktok', function (): void {
    $user = User::factory()->create();
    $account = SocialAccount::query()->create([
        'user_id' => $user->id,
        'platform' => 'tiktok',
        'name' => 'x',
        'login_email' => 'a@b.com',
        'login_password' => 'p',
        'is_active' => true,
    ]);

    Livewire::actingAs($user)
        ->test(Index::class)
        ->call('delete', $account->id);

    expect(SocialAccount::query()->find($account->id))->toBeNull();
});
