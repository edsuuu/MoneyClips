<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `platform` é ONDE a conta posta (youtube|tiktok); `provider` é POR ONDE
 * (API oficial ou TikTokUploader). As contas existentes só
 * conheciam um caminho por plataforma, daí o backfill direto.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('social_accounts', function (Blueprint $table): void {
            $table->string('provider', 32)->nullable()->after('platform');
        });

        DB::table('social_accounts')->where('platform', 'youtube')->update(['provider' => 'youtube_api']);
        DB::table('social_accounts')->where('platform', 'tiktok')->update(['provider' => 'tiktok_uploader']);

        Schema::table('social_accounts', function (Blueprint $table): void {
            $table->string('provider', 32)->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('social_accounts', function (Blueprint $table): void {
            $table->dropColumn('provider');
        });
    }
};
