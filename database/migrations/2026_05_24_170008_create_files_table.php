<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('files', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('video_id')->constrained()->cascadeOnDelete();
            $table->foreignId('cut_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('type'); // original, audio, legendado, pt1, pt2, thumbnail
            $table->string('path', 1024);
            $table->string('mime_type')->nullable();
            $table->string('extension', 16)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->string('checksum_sha256', 64)->nullable();
            $table->timestamps();

            $table->index(['video_id', 'type']);
            $table->index('cut_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('files');
    }
};
