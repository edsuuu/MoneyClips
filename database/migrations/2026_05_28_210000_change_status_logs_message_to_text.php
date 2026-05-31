<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('status_logs', function (Blueprint $table): void {
            // VARCHAR(255) estourava com tracebacks de ffmpeg/erros longos
            // vindos do Python (ex.: "Function not implemented" + stderr completo).
            $table->text('message')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('status_logs', function (Blueprint $table): void {
            $table->string('message')->nullable()->change();
        });
    }
};
