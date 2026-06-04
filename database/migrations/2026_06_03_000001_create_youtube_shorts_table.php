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
            $table->string('title')->nullable();
            $table->text('description')->nullable();
            $table->json('hashtags')->nullable();
            $table->string('minio_path')->nullable();
            $table->timestamp('downloaded_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('youtube_shorts');
    }
};
