<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('video_cuts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('video_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('start_seconds');
            $table->unsignedInteger('end_seconds');
            $table->boolean('is_ai_generated')->default(false);
            $table->string('status')->default('draft');
            $table->string('transcription_status')->nullable();
            $table->timestamp('edited_at')->nullable();
            $table->text('error')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('video_cuts');
    }
};
