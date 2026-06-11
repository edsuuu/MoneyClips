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
            $table->unsignedTinyInteger('progress')->default(0)->after('status_id');
            $table->string('download_stage')->nullable()->after('progress'); // video | audio
        });
    }

    public function down(): void
    {
        Schema::table('videos', function (Blueprint $table): void {
            $table->dropColumn(['progress', 'download_stage']);
        });
    }
};
