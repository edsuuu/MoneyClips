<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('drive_videos', function (Blueprint $table): void {
            $table->id();
            // ID do arquivo no Google Drive — dedupe (não rebaixar o mesmo vídeo).
            $table->string('drive_file_id')->unique();
            $table->string('drive_folder_id')->index();
            // Link da pasta do Drive de onde o vídeo veio.
            $table->string('drive_folder_url');
            $table->string('folder_name')->nullable();
            $table->string('title');
            // Caminho no MinIO (bucket videos): drive/<pasta>/<arquivo>.
            $table->string('video_path');
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->timestamp('downloaded_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('drive_videos');
    }
};
