<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('video_cuts_edits', function (Blueprint $table): void {
            $table->string('caption_preset', 32)->nullable()->after('ai_request');
        });
    }

    public function down(): void
    {
        Schema::table('video_cuts_edits', function (Blueprint $table): void {
            $table->dropColumn('caption_preset');
        });
    }
};
