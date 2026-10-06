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
            $table->decimal('start_seconds', 10, 3)->change();
            $table->decimal('end_seconds', 10, 3)->change();
        });
    }

    public function down(): void
    {
        Schema::table('video_cuts', function (Blueprint $table): void {
            $table->unsignedInteger('start_seconds')->change();
            $table->unsignedInteger('end_seconds')->change();
        });
    }
};
