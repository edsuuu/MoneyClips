<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('status_logs', function (Blueprint $table): void {
            $table->id();
            $table->morphs('statusable'); // statusable_type, statusable_id
            $table->foreignId('from_status_id')->nullable()->constrained('statuses');
            $table->foreignId('to_status_id')->constrained('statuses');
            $table->text('message')->nullable();
            $table->json('context')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('status_logs');
    }
};
