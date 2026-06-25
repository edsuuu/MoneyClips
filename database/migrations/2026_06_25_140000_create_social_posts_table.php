<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ledger genérico de postagens em redes sociais. Substitui tiktok_posts
 * (que era específico de uma plataforma). A coluna `platform` discrimina
 * o destino — assim, adicionar Instagram/X/etc. depois é trivial.
 *
 * Os dados antigos da tiktok_posts são migrados pelo artisan
 * `posts:migrate-tiktok` antes do drop da tabela legada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_posts', function (Blueprint $table): void {
            $table->id();
            // tiktok | youtube | instagram | x | facebook ...
            $table->string('platform', 32)->index();
            // ID do job na API do microserviço da plataforma (uploader, etc.).
            $table->uuid('uuid')->unique();
            // Short de origem (youtube_shorts.youtube_id). Null no modo legado
            // até o sorteio do vídeo acontecer dentro do uploader.
            $table->string('youtube_id')->nullable();
            // Chave do vídeo no storage (ex.: shorts/{id}.mp4).
            $table->text('video_key')->nullable();
            $table->string('title')->nullable();
            $table->json('hashtags')->nullable();
            // Conta lógica usada no post (ex.: TIKTOK_ACCOUNT_NAME).
            $table->string('account_name')->nullable();
            // queued | processing | completed | dry-run | failed | skipped
            $table->string('status', 32)->default('queued')->index();
            $table->text('error')->nullable();
            $table->timestamp('requested_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();

            // Lookup mais comum: "tem post pra esse short nessa plataforma?".
            $table->index(['platform', 'youtube_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_posts');
    }
};
