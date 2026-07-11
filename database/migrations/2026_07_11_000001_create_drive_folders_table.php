<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('drive_folders', function (Blueprint $table): void {
            $table->id();
            $table->string('drive_folder_id')->unique();
            $table->string('url');
            $table->string('name')->nullable();
            // true = pasta baixada por completo (nenhum vídeo falhou). Ao rodar
            // o comando de novo, pastas true são puladas (a menos de --force).
            $table->boolean('downloaded')->default(false);
            $table->unsignedInteger('videos_count')->nullable();
            $table->timestamp('downloaded_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('drive_folders');
    }
};
