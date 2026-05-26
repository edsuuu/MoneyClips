<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_post_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('scheduled_post_id')->constrained()->cascadeOnDelete();
            $table->string('level', 16)->default('info'); // info, warning, error
            $table->string('message');
            $table->json('context')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['scheduled_post_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_post_logs');
    }
};
