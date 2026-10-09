<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Uma linha por Short × conta: o horário mora na própria linha
 * (`scheduled_for`), sem tabela de slots. O unique trava a dupla postagem do
 * mesmo Short na mesma conta.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_posts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('youtube_short_id')->constrained()->cascadeOnDelete();
            $table->foreignId('social_account_id')->constrained()->cascadeOnDelete();
            $table->dateTime('scheduled_for');
            $table->string('status', 16);
            $table->string('privacy', 16)->nullable();
            $table->string('external_id')->nullable()->index();
            $table->string('url')->nullable();
            $table->text('error')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();

            $table->unique(['youtube_short_id', 'social_account_id']);
            $table->index(['status', 'scheduled_for']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_posts');
    }
};
