<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('video_payloads', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('video_id')->constrained()->cascadeOnDelete();
            $table->string('type'); // ingest_request, ingest_result, transcript_raw, ...
            $table->json('payload');
            $table->timestamps();

            $table->index(['video_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('video_payloads');
    }
};
