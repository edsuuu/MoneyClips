<?php

declare(strict_types=1);

use App\Enums\RoleEnum;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Role;

return new class extends Migration
{
    /**
     * Quem existia antes dos papéis vira admin UMA vez (migration, não
     * seeder: o seeder roda de novo e promoveria qualquer usuário sem papel).
     * Usuário novo nasce creator no OAuthController — o papel já nasce aqui
     * pra o login não depender de rodar o seeder depois do migrate.
     */
    public function up(): void
    {
        foreach (RoleEnum::cases() as $role) {
            Role::findOrCreate($role->value);
        }

        foreach (User::query()->doesntHave('roles')->get() as $user) {
            $user->assignRole(RoleEnum::Admin);
        }
    }

    public function down(): void {}
};
