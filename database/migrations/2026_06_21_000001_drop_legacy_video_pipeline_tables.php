<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Onda 3 — remove o pipeline legado de cortes/vídeos longos.
 *
 * Os módulos Livewire Videos/*, controllers, services e models que dependiam
 * dessas tabelas saíram do app no mesmo PR. Foco da plataforma agora é
 * exclusivamente auto-postagem de Shorts (youtube_shorts + tiktok_posts).
 */
return new class extends Migration
{
    /** @var list<string> ordem importa: dependents antes de dependencies. */
    private const array LEGACY_TABLES = [
        'social_post_logs',
        'scheduled_posts',
        'cuts',
        'files',
        'transcripts',
        'video_payloads',
        'status_logs',
        'videos',
        'statuses',
    ];

    public function up(): void
    {
        foreach (self::LEGACY_TABLES as $table) {
            Schema::dropIfExists($table);
        }
    }

    public function down(): void
    {
        // Sem rollback: as migrations originais foram removidas junto. Restaurar
        // o pipeline legado significa reverter este PR inteiro via git.
    }
};
