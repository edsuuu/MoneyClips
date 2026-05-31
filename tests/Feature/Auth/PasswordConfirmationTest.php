<?php

declare(strict_types=1);

use App\Models\User;

test('confirm password screen can be rendered', function (): void {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('password.confirm'));

    $response->assertOk();
});

test('google authenticated sessions skip the password confirmation screen', function (): void {
    $user = User::factory()->create(['google_id' => 'google-123']);

    $response = $this->actingAs($user)
        ->withSession(['auth.authenticated_via_google' => true])
        ->get(route('password.confirm'));

    $response->assertRedirect(route('dashboard'));
});
