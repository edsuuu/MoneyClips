<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('youtube_short_jobs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('youtube_short_id')->constrained()->cascadeOnDelete();
            $table->dateTime('scheduled_at');
            $table->timestamp('posted_at')->nullable();
            $table->string('status', 16)->default('pending'); // pending, posted, failed
            $table->boolean('discord_notified')->default(false);
            $table->timestamps();

            $table->index(['status', 'scheduled_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('youtube_short_jobs');
    }
};
