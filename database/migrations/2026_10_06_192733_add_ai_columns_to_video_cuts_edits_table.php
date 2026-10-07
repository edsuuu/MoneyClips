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
            $table->string('ai_status', 16)->nullable()->after('tracking_error');
            $table->text('ai_error')->nullable()->after('ai_status');
            $table->string('ai_request', 300)->nullable()->after('ai_error');
        });
    }

    public function down(): void
    {
        Schema::table('video_cuts_edits', function (Blueprint $table): void {
            $table->dropColumn(['ai_status', 'ai_error', 'ai_request']);
        });
    }
};
