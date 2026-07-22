<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vídeos longos enviados pelo operador na tela /upload — a entidade lógica.
 *
 * Só campos objetivos do vídeo: nenhum path mora aqui. Todo binário/artefato
 * (original, áudio, HLS, poster, storyboard, cortes) vira uma linha em `files`.
 * `hash` é o md5 do original (dedup futuro); `name` é o título editável para
 * achar o vídeo depois.
 *
 * Ciclo (status): awaiting_upload → uploaded → packaging → ready.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('videos', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->uuid('uuid')->unique();
            $table->string('hash', 32)->nullable()->index();
            $table->string('name')->nullable();
            $table->string('status', 32)->default('awaiting_upload');
            $table->unsignedTinyInteger('progress')->default(0);
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->unsignedSmallInteger('width')->nullable();
            $table->unsignedSmallInteger('height')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('ready_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('videos');
    }
};
