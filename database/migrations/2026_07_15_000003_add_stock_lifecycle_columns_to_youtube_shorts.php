<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ciclo de vida do estoque (tela "Meus vídeos"):
 * - ready_at        → "pronto para postar" (botão Adicionar à fila / editor de template);
 * - processed_video_path → saída do reencode/template no MinIO — quando presente,
 *                     é o que os posters publicam (postableVideoPath());
 * - template_rendered_at → alimenta a tab "Com template".
 *
 * dispatched_at do short deixa de ser lock de sorteio (o claim agora é do
 * schedule_slots.dispatched_at) — a coluna fica só como histórico.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('youtube_shorts', function (Blueprint $table): void {
            $table->timestamp('ready_at')->nullable()->after('downloaded_at');
            $table->string('processed_video_path')->nullable()->after('video_path');
            $table->timestamp('template_rendered_at')->nullable()->after('ready_at');
            $table->index('ready_at');
        });

        // Backfill: o estoque "disponível" de hoje (com vídeo + hashtags) já
        // era considerado pronto pelo sorteio antigo — preserva como pronto.
        DB::table('youtube_shorts')
            ->whereNotNull('video_path')
            ->whereNotNull('hashtags')
            ->update(['ready_at' => DB::raw('COALESCE(downloaded_at, created_at)')]);
    }

    public function down(): void
    {
        Schema::table('youtube_shorts', function (Blueprint $table): void {
            $table->dropIndex(['ready_at']);
            $table->dropColumn(['ready_at', 'processed_video_path', 'template_rendered_at']);
        });
    }
};
