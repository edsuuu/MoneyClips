<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Configuração runtime do AutoPostDispatcher. Mantida em DB pra evitar
 * SSH+config:cache toda vez que o operador quer ligar/desligar uma
 * plataforma. Apenas 1 row de fato (singleton pattern), com auditoria de
 * quem mudou e quando.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('auto_post_settings', function (Blueprint $table): void {
            $table->id();
            $table->boolean('youtube_enabled')->default(true);
            $table->boolean('tiktok_enabled')->default(true);
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // Seed da única row, herdando os valores das envs atuais.
        DB::table('auto_post_settings')->insert([
            'youtube_enabled' => filter_var(env('YOUTUBE_POSTING_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
            'tiktok_enabled' => filter_var(env('TIKTOK_POSTING_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('auto_post_settings');
    }
};
