<?php

declare(strict_types=1);

use App\Models\User;

test('linked accounts page is displayed inside settings navigation', function (): void {
    $this->actingAs($user = User::factory()->create());

    $this->get(route('settings.accounts'))
        ->assertOk()
        ->assertSee('Contas vinculadas')
        ->assertSee('YouTube')
        ->assertSee('TikTok')
        ->assertSee('/settings/accounts', escape: false)
        ->assertDontSee('/social-accounts', escape: false);
});

test('legacy linked accounts route still resolves for authenticated users', function (): void {
    $this->actingAs($user = User::factory()->create());

    $this->get(route('social-accounts'))
        ->assertOk()
        ->assertSee('Contas vinculadas')
        ->assertSee('YouTube')
        ->assertSee('TikTok');
});
