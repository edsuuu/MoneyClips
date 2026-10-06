<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_assets', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('kind');
            $table->json('tags');
            $table->string('emotion')->nullable();
            $table->string('license');
            $table->string('source');
            $table->string('source_url')->nullable();
            $table->string('storage_key');
            $table->unsignedInteger('duration_ms')->nullable();
            $table->unsignedSmallInteger('width')->nullable();
            $table->unsignedSmallInteger('height')->nullable();
            $table->string('author')->nullable();
            $table->string('status')->default('pending');
            $table->boolean('shows_real_person')->default(false);
            $table->boolean('has_audio')->default(false);
            $table->string('risk_note')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_assets');
    }
};
