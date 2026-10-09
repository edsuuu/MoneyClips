<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\PermissionEnum;
use App\Enums\RoleEnum;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

final class Seeder001Roles extends Seeder
{
    public function run(): void
    {
        resolve(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (PermissionEnum::cases() as $permission) {
            Permission::query()->firstOrCreate(['name' => $permission->value, 'guard_name' => 'web'], ['group' => Str::before($permission->value, '.')]);
        }

        $admin = Role::findOrCreate(RoleEnum::Admin->value);
        $admin->syncPermissions(PermissionEnum::cases());
        Role::findOrCreate(RoleEnum::Creator->value);
        Role::query()->whereNotIn('name', array_column(RoleEnum::cases(), 'value'))->delete();
    }
}
