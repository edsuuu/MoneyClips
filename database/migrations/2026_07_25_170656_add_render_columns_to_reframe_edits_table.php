<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reframe_edits', function (Blueprint $table): void {
            // null = nunca renderizado; generating|ready|failed (VideoCutStatusEnum).
            $table->string('render_status', 16)->nullable()->after('settings');
            $table->string('rendered_path')->nullable()->after('render_status');
            $table->string('render_error')->nullable()->after('rendered_path');
        });
    }

    public function down(): void
    {
        Schema::table('reframe_edits', function (Blueprint $table): void {
            $table->dropColumn(['render_status', 'rendered_path', 'render_error']);
        });
    }
};
