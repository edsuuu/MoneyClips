<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Toggles de auto-postagem viram preferências por usuário (colunas em users).
 * Substitui a tabela auto_post_settings, que era um pseudo-singleton e
 * adicionava 1 join pra ler 2 bools — agora basta um SELECT no users.
 *
 * O cron (sem auth) lê do primeiro user (admin, id=1) — ver YoutubePosterService
 * e TiktokPosterService.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('auto_post_youtube_enabled')->default(true)->after('remember_token');
            $table->boolean('auto_post_tiktok_enabled')->default(true)->after('auto_post_youtube_enabled');
        });

        // Migra a row única do legado pra TODOS os users. Não há perda — a
        // semântica antiga (1 toggle global) é equivalente a "todos os users
        // herdam o mesmo valor inicial". Depois cada user pode mexer no seu.
        if (Schema::hasTable('auto_post_settings')) {
            $row = DB::table('auto_post_settings')->orderBy('id')->first();
            if ($row !== null) {
                DB::table('users')->update([
                    'auto_post_youtube_enabled' => (bool) $row->youtube_enabled,
                    'auto_post_tiktok_enabled' => (bool) $row->tiktok_enabled,
                ]);
            }

            Schema::drop('auto_post_settings');
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['auto_post_youtube_enabled', 'auto_post_tiktok_enabled']);
        });
    }
};
