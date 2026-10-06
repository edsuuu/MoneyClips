<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('video_cuts', function (Blueprint $table): void {
            $table->unsignedTinyInteger('score')->nullable()->after('is_ai_generated');
            $table->text('reason')->nullable()->after('score');
            $table->string('title', 150)->nullable()->after('reason');
            $table->json('hashtags')->nullable()->after('title');
        });
    }

    public function down(): void
    {
        Schema::table('video_cuts', function (Blueprint $table): void {
            $table->dropColumn(['score', 'reason', 'title', 'hashtags']);
        });
    }
};
