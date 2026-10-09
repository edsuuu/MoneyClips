<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Modo da conta (`SocialAccountModeEnum`) sai de dois booleans:
 * Desligada = !is_active, Manual = ativa sem auto_schedule, Automática =
 * ativa com auto_schedule. Conta existente começa em Manual.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('social_accounts', function (Blueprint $table): void {
            $table->boolean('auto_schedule')->default(false)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('social_accounts', function (Blueprint $table): void {
            $table->dropColumn('auto_schedule');
        });
    }
};
