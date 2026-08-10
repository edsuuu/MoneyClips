<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A postagem (YouTube + TikTok) saiu do app — o ledger vai junto. Quando a
 * postagem for refeita, ela nasce com o modelo de dados novo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('social_posts');
    }

    public function down(): void
    {
        Schema::create('social_posts', function (Blueprint $table): void {
            $table->id();
            $table->string('platform', 32)->index();
            $table->uuid('uuid')->unique();
            $table->string('youtube_id')->nullable();
            $table->text('video_key')->nullable();
            $table->string('title')->nullable();
            $table->json('hashtags')->nullable();
            $table->string('account_name')->nullable();
            $table->string('status', 32)->default('queued')->index();
            $table->text('error')->nullable();
            $table->timestamp('requested_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();

            $table->index(['platform', 'youtube_id']);
        });
    }
};
