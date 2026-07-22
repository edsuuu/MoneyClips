<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Artefatos de um vídeo no MinIO — 1 vídeo tem N files.
 *
 * Cada linha é UM artefato lógico (o HLS inteiro é a `master.m3u8`, não os
 * milhares de segmentos). `type` categoriza; `path` é a chave S3; `meta` guarda
 * o específico do tipo (renditions do hls, grade do storyboard, trecho do clip).
 * `upload_id` só vive no original enquanto o multipart do MinIO está aberto.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('files', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('video_id')->constrained()->cascadeOnDelete();
            $table->string('type', 24);
            $table->string('path');
            $table->string('upload_id')->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->string('mime_type', 128)->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['video_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('files');
    }
};
