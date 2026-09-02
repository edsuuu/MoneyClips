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
            // null = tracking nunca rodado; processing|ready|failed (TranscriptionStatusEnum).
            $table->string('tracking_status', 16)->nullable()->after('render_error');
            $table->string('tracking_error')->nullable()->after('tracking_status');
        });
    }

    public function down(): void
    {
        Schema::table('video_cuts_edits', function (Blueprint $table): void {
            $table->dropColumn(['tracking_status', 'tracking_error']);
        });
    }
};
