<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('files', function (Blueprint $table): void {
            $table->foreignId('video_cut_id')->nullable()->after('video_id')->constrained()->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('files', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('video_cut_id');
        });
    }
};
