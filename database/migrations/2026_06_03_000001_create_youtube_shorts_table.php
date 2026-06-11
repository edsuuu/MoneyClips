<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('youtube_shorts', function (Blueprint $table): void {
            $table->id();
            $table->string('youtube_id')->unique();
            $table->string('channel_url')->nullable();
            $table->string('title')->nullable();
            $table->json('hashtags')->nullable();
            $table->string('video_path')->nullable();
            // ID do vídeo gerado no YouTube após a postagem.
            $table->string('youtube_video_id')->nullable();
            $table->timestamp('downloaded_at')->nullable();
            // null = ainda não postado / disponível para sorteio.
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('youtube_shorts');
    }
};
