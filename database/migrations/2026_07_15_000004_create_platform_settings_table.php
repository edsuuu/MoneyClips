<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Toggle global por plataforma de postagem. Substitui as colunas
 * users.auto_post_{youtube,tiktok}_enabled (fonte de verdade acoplada ao
 * "1º user admin") — multi-plataforma precisa de uma linha por plataforma.
 *
 * Semeia as 6 plataformas: youtube/tiktok herdam os toggles atuais do 1º
 * user; as oficiais (tiktok_official, instagram, facebook, kwai) nascem
 * desabilitadas (posters ainda são stubs).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_settings', function (Blueprint $table): void {
            $table->id();
            // youtube | tiktok | tiktok_official | instagram | facebook | kwai
            $table->string('platform', 32)->unique();
            $table->string('display_name');
            $table->boolean('enabled')->default(false);
            $table->timestamps();
        });

        $admin = DB::table('users')->orderBy('id')->first();
        $now = now();

        DB::table('platform_settings')->insert([
            ['platform' => 'youtube', 'display_name' => 'YouTube', 'enabled' => (bool) ($admin?->auto_post_youtube_enabled ?? true), 'created_at' => $now, 'updated_at' => $now],
            ['platform' => 'tiktok', 'display_name' => 'TikTok', 'enabled' => (bool) ($admin?->auto_post_tiktok_enabled ?? true), 'created_at' => $now, 'updated_at' => $now],
            ['platform' => 'tiktok_official', 'display_name' => 'TikTok (API oficial)', 'enabled' => false, 'created_at' => $now, 'updated_at' => $now],
            ['platform' => 'instagram', 'display_name' => 'Instagram Reels', 'enabled' => false, 'created_at' => $now, 'updated_at' => $now],
            ['platform' => 'facebook', 'display_name' => 'Facebook Reels', 'enabled' => false, 'created_at' => $now, 'updated_at' => $now],
            ['platform' => 'kwai', 'display_name' => 'Kwai', 'enabled' => false, 'created_at' => $now, 'updated_at' => $now],
        ]);

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['auto_post_youtube_enabled', 'auto_post_tiktok_enabled']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('auto_post_youtube_enabled')->default(true);
            $table->boolean('auto_post_tiktok_enabled')->default(true);
        });

        Schema::dropIfExists('platform_settings');
    }
};
