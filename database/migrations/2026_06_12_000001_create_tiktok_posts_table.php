<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ledger de postagens no TikTok. As linhas são criadas e atualizadas pela
     * API do microserviço tiktok-uploader (Node), que compartilha este banco;
     * o Laravel lê para sortear pendentes e exibir o histórico.
     */
    public function up(): void
    {
        Schema::create('tiktok_posts', function (Blueprint $table): void {
            $table->id();
            // ID do job na API do tiktok-uploader.
            $table->uuid('uuid')->unique();
            // Short de origem (estoque do download-shorts). Null no modo legado
            // até o sorteio do vídeo acontecer dentro do uploader.
            $table->string('youtube_id')->nullable()->index();
            // Chave do vídeo no storage (ex.: shorts/{id}.mp4).
            $table->text('video_key')->nullable();
            $table->string('title')->nullable();
            $table->json('hashtags')->nullable();
            // Conta lógica do TikTok usada no post (TIKTOK_ACCOUNT_NAME).
            $table->string('account_name')->nullable();
            // queued | processing | completed | dry-run | failed | skipped
            $table->string('status', 32)->default('queued')->index();
            $table->text('error')->nullable();
            $table->timestamp('requested_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tiktok_posts');
    }
};
