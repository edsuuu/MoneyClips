<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_accounts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('platform', 32);                 // youtube, tiktok, instagram, facebook
            $table->string('name');                         // rótulo: canal / @handle / página
            $table->string('external_account_id')->nullable(); // channel_id / ig_user_id / page_id / open_id
            $table->text('access_token')->nullable();       // criptografado (cast 'encrypted')
            $table->text('refresh_token')->nullable();      // criptografado
            $table->timestamp('token_expires_at')->nullable();
            $table->json('scopes')->nullable();
            $table->json('meta')->nullable();               // extras por plataforma (page_id, ig_user_id, ...)
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['platform', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_accounts');
    }
};
