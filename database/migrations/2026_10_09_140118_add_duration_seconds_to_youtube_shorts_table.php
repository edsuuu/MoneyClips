<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('youtube_shorts', function (Blueprint $table): void {
            $table->unsignedSmallInteger('duration_seconds')->nullable()->after('video_path');
        });
    }

    public function down(): void
    {
        Schema::table('youtube_shorts', function (Blueprint $table): void {
            $table->dropColumn('duration_seconds');
        });
    }
};
