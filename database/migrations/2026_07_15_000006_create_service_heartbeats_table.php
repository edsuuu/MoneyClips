<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Heartbeat dos microserviços (OBSERVABILITY.md): cada serviço faz POST
 * /api/observability/heartbeat a cada 30s; upsert por `service` = 1 linha
 * por serviço. `last_seen_at` > 90s = serviço fora do ar (alerta Discord).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_heartbeats', function (Blueprint $table): void {
            $table->id();
            $table->string('service', 64)->unique();
            $table->string('hostname')->nullable();
            $table->string('version', 32)->nullable();
            $table->unsignedBigInteger('uptime_seconds')->default(0);
            $table->unsignedInteger('memory_mb')->nullable();
            $table->timestamp('last_seen_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_heartbeats');
    }
};
