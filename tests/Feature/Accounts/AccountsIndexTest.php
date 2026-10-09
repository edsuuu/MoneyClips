<?php

declare(strict_types=1);

use App\Livewire\Accounts\Index;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;

const COOKIES_JSON = '[{"name":"sessionid","value":"top-secret-cookie-value"}]';

beforeEach(function (): void {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

it('has no login credential columns', function (): void {
    expect(Schema::hasColumns('social_accounts', ['login_email', 'login_password']))->toBeFalse();
});

it('stores cookies encrypted and never echoes them back', function (): void {
    Livewire::test(Index::class)
        ->set('name', '@clips')
        ->set('cookiesInput', COOKIES_JSON)
        ->call('saveTiktok')
        ->assertHasNoErrors()
        ->assertSet('cookiesInput', '')
        ->assertDontSee('top-secret-cookie-value');

    $account = SocialAccount::query()->firstOrFail();
    expect($account->cookies)->toBe([['name' => 'sessionid', 'value' => 'top-secret-cookie-value']])
        ->and($account->getRawOriginal('cookies'))->not->toContain('top-secret-cookie-value')
        ->and($account->cookies_last_validated_at)->not->toBeNull();
});

it('does not hydrate secrets into the html or the livewire snapshot when editing', function (): void {
    $account = SocialAccount::query()->create([
        'user_id' => $this->user->id,
        'platform' => 'tiktok',
        'name' => '@clips',
        'cookies' => [['name' => 'sessionid', 'value' => 'top-secret-cookie-value']],
        'cookies_last_validated_at' => now(),
    ]);

    $component = Livewire::test(Index::class)->call('editTiktok', $account->id);

    expect($component->html())->not->toContain('top-secret-cookie-value')->toContain('Sessão salva em');
    expect(json_encode($component->snapshot, JSON_THROW_ON_ERROR))->not->toContain('top-secret-cookie-value');
    $component->assertSet('cookiesInput', '');
});

it('keeps the saved session when editing without new cookies', function (): void {
    $account = SocialAccount::query()->create([
        'user_id' => $this->user->id,
        'platform' => 'tiktok',
        'name' => '@clips',
        'cookies' => [['name' => 'sessionid', 'value' => 'old']],
    ]);

    Livewire::test(Index::class)
        ->call('editTiktok', $account->id)
        ->set('name', '@renamed')
        ->call('saveTiktok')
        ->assertHasNoErrors();

    expect($account->refresh()->name)->toBe('@renamed')
        ->and($account->cookies)->toBe([['name' => 'sessionid', 'value' => 'old']]);
});

it('requires valid cookies json on create', function (): void {
    Livewire::test(Index::class)
        ->set('name', '@clips')
        ->call('saveTiktok')
        ->assertHasErrors(['cookiesInput' => 'required'])
        ->set('cookiesInput', 'nope')
        ->call('saveTiktok')
        ->assertHasErrors(['cookiesInput' => 'json'])
        ->set('cookiesInput', '"scalar"')
        ->call('saveTiktok')
        ->assertHasErrors('cookiesInput');
});
