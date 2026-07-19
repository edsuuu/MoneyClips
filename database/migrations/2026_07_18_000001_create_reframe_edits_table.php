<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Edição de reframe/crop feita no /estudio-de-cortes (client-side, canvas).
 * Guarda só o ESTADO do editor — keyframes com coordenadas normalizadas
 * (0–1, relativas ao tamanho natural da fonte) — pronto para alimentar um
 * render ffmpeg futuro sem depender da resolução usada na prévia.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reframe_edits', function (Blueprint $table): void {
            $table->id();
            // Correlação com o futuro job de render (estilo processing_jobs).
            $table->uuid('uuid')->unique();
            // FK quando o vídeo veio do estoque; uploads futuros ficam com
            // FK null e só source_path (referência canônica no MinIO).
            $table->foreignId('youtube_short_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source_path');
            // Snapshot da fonte capturado no client (loadedmetadata):
            // {width, height, duration}. Valida keyframes e alimenta o render.
            $table->json('source_meta')->nullable();
            // vertical | split | trio | spotlight | centered
            $table->string('mode', 32);
            // [{t, regions: [{x, y, w, h}, ...]}, ...] — coords normalizadas.
            $table->json('keyframes');
            // {version, background} — cor das barras dos modos contain.
            $table->json('settings')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reframe_edits');
    }
};
