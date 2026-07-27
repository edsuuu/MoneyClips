<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::rename('reframe_edits', 'video_cuts_edits');

        Schema::table('video_cuts_edits', function (Blueprint $table): void {
            $table->dropColumn(['source_path', 'rendered_path']);
        });
    }

    public function down(): void
    {
        Schema::table('video_cuts_edits', function (Blueprint $table): void {
            $table->string('source_path')->after('video_cut_id');
            $table->string('rendered_path')->nullable()->after('render_status');
        });

        Schema::rename('video_cuts_edits', 'reframe_edits');
    }
};
