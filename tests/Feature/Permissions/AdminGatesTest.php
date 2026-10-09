<?php

declare(strict_types=1);

use App\Enums\RoleEnum;
use App\Models\User;
use Database\Seeders\Seeder001Roles;

it('only lets admin open observability and the log viewer', function (): void {
    $this->seed(Seeder001Roles::class);

    $this->get('/observabilidade')->assertRedirect(route('login'));
    $this->get('/log-viewer')->assertForbidden();

    $this->actingAs(User::factory()->create()->assignRole(RoleEnum::Creator));
    $this->get('/observabilidade')->assertForbidden()->assertSee('Você não tem permissão');
    $this->get('/log-viewer')->assertForbidden();

    $this->actingAs(User::factory()->create()->assignRole(RoleEnum::Admin));
    $this->get('/observabilidade')->assertOk();
    $this->get('/log-viewer')->assertOk();
});

it('promotes users created before the roles to admin once', function (): void {
    $existing = User::factory()->create();

    $migration = require database_path('migrations/2026_10_08_235847_promote_existing_users_to_admin.php');
    $migration->up();
    $later = User::factory()->create();
    $this->seed(Seeder001Roles::class);

    expect($existing->refresh()->isAdmin())->toBeTrue()
        ->and($later->refresh()->isAdmin())->toBeFalse();
});
