<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('youtube_shorts', function (Blueprint $table): void {
            // Reserva: setado no instante do sorteio (social:dispatch-posts) para
            // o mesmo Short nunca ser sorteado de novo, mesmo antes de confirmar
            // o sucesso das postagens.
            $table->timestamp('dispatched_at')->nullable()->after('posted_at');

            // Confirmações de sucesso por plataforma — ambas retornam sucesso:
            // YouTube pela Data API (ShortsPoster), TikTok pelo callback do
            // microserviço uploader (TiktokPostCallbackController).
            $table->timestamp('posted_youtube_at')->nullable()->after('dispatched_at');
            $table->timestamp('posted_tiktok_at')->nullable()->after('posted_youtube_at');
        });

        // Sem backfill: posted_at é legado. A UI deve contar só confirmações
        // explícitas gravadas daqui em diante por plataforma.
    }

    public function down(): void
    {
        Schema::table('youtube_shorts', function (Blueprint $table): void {
            $table->dropColumn(['dispatched_at', 'posted_youtube_at', 'posted_tiktok_at']);
        });
    }
};
