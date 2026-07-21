<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Logs centralizados dos microserviços: os serviços fazem
 * push em lote pra POST /api/observability/logs; a tela /observabilidade lê
 * daqui com polling. Prune de 14 dias via ServiceLog::prunable().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_logs', function (Blueprint $table): void {
            $table->id();
            $table->string('service', 64);
            $table->string('hostname')->nullable();
            // debug | info | warn | error
            $table->string('level', 16)->index();
            $table->text('message');
            // Payload extra (stack trace etc.) — mostrado no drawer de detalhe.
            $table->json('context')->nullable();
            // Timestamp do lado do serviço (created_at é o de chegada).
            $table->timestamp('logged_at');
            $table->timestamp('created_at')->nullable();

            $table->index(['service', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_logs');
    }
};
