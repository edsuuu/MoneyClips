<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Estado do pipeline de processamento de vídeo (reencode / template via
 * AutoCaption). O Laravel orquestra: baixa o vídeo do MinIO, envia ao
 * microserviço, grava a saída de volta no MinIO (regra: só o Laravel toca
 * o S3). Uma linha por execução; o vínculo com o vídeo é youtube_short_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('processing_jobs', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('youtube_short_id')->constrained()->cascadeOnDelete();
            // reencode | template
            $table->string('type', 32);
            // queued | processing | completed | failed
            $table->string('status', 32)->default('queued');
            // Opções do render: style, channel_name, channel_handle, mark_ready.
            $table->json('options')->nullable();
            // uuid do job no AutoCaption (correlaciona o webhook).
            $table->string('remote_id')->nullable();
            // Chave da saída no MinIO (gravada pelo Laravel).
            $table->string('output_path')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['youtube_short_id', 'type']);
            $table->index('remote_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('processing_jobs');
    }
};
