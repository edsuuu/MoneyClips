<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adiciona armazenamento de cookies/sessão diretamente no social_accounts.
 * Antes os cookies do TikTok viviam num arquivo dentro do container do
 * microserviço (MicroServices/TikTokUploader/cookies/{name}.json) — agora
 * a fonte da verdade é o banco. O Laravel envia os cookies no payload do
 * POST /posts; o microserviço devolve eventuais cookies refrescados no
 * callback e atualizamos a row.
 *
 * Colunas:
 *  - cookies: JSON criptografado (cast 'encrypted:array' no model).
 *  - cookies_last_validated_at: timestamp da última vez que o uploader
 *    confirmou sessão válida (post bem-sucedido).
 *  - session_status: valid | invalid | unknown (default null = unknown).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('social_accounts', function (Blueprint $table): void {
            $table->text('cookies')->nullable()->after('meta');
            $table->timestamp('cookies_last_validated_at')->nullable()->after('cookies');
            $table->string('session_status', 16)->nullable()->after('cookies_last_validated_at');
        });
    }

    public function down(): void
    {
        Schema::table('social_accounts', function (Blueprint $table): void {
            $table->dropColumn(['cookies', 'cookies_last_validated_at', 'session_status']);
        });
    }
};
