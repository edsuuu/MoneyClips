<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scheduled_posts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('video_id')->constrained()->cascadeOnDelete();
            $table->foreignId('cut_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('social_account_id')->nullable()->constrained()->nullOnDelete();
            $table->string('platform', 32);                 // youtube, tiktok, instagram, facebook
            $table->unsignedInteger('sequence')->default(0); // ordem de publicação
            $table->string('title')->nullable();
            $table->text('description')->nullable();
            $table->json('hashtags')->nullable();
            $table->dateTime('scheduled_for');
            // pending, scheduled, publishing, posted, failed, cancelled
            $table->string('status', 24)->default('pending');
            $table->string('external_post_id')->nullable(); // id retornado pela plataforma
            $table->string('external_url')->nullable();      // link público do post
            $table->text('error_message')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('posted_at')->nullable();
            $table->json('payload')->nullable();             // request/response bruto para auditoria
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index(['status', 'scheduled_for']);
            $table->index(['video_id', 'sequence']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduled_posts');
    }
};
