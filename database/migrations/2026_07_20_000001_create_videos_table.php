<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vídeos enviados pelo operador na tela /upload.
 *
 * O binário vive no MinIO em `uploads/{uuid}` — não existe coluna de path
 * porque a chave é derivável do uuid (Video::path()). `hash` é o md5 do
 * binário, indexado para deduplicação futura (mesmo arquivo reenviado).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('videos', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->uuid('uuid')->unique();
            $table->string('hash', 32)->index();
            $table->unsignedBigInteger('file_size');
            $table->string('mime_type', 128);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('videos');
    }
};
