<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Credenciais de login da plataforma (hoje TikTok, que não tem OAuth oficial).
 * O Laravel guarda email/senha pra futuramente disparar o login automático no
 * microserviço uploader (POST /login) e gerar/renovar os cookies da sessão.
 *
 * ATENÇÃO: gravadas em TEXTO PURO nesta fase (decisão do produto). Upgrade path:
 * castar 'login_password' como 'encrypted' no model SocialAccount (mesmo cast já
 * usado por access_token/cookies) e re-salvar as rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('social_accounts', function (Blueprint $table): void {
            $table->string('login_email')->nullable()->after('session_status');
            $table->string('login_password')->nullable()->after('login_email');
        });
    }

    public function down(): void
    {
        Schema::table('social_accounts', function (Blueprint $table): void {
            $table->dropColumn(['login_email', 'login_password']);
        });
    }
};
