<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('videos', function (Blueprint $table): void {
            // null = busca nunca rodada; processing|ready|failed (TranscriptionStatusEnum).
            $table->string('cut_suggestion_status', 16)->nullable()->after('transcription_status');
            $table->text('cut_suggestion_error')->nullable()->after('cut_suggestion_status');
        });
    }

    public function down(): void
    {
        Schema::table('videos', function (Blueprint $table): void {
            $table->dropColumn(['cut_suggestion_status', 'cut_suggestion_error']);
        });
    }
};
