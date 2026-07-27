<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

final class Seeder001Roles extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Role::query()->updateOrCreate(['name' => 'Tecnologia']);
        Role::query()->updateOrCreate(['name' => 'Cliente']);
    }
}
