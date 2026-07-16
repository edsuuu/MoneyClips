<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Liga o ledger de postagens ao slot da agenda: 1 linha por (slot, plataforma)
 * é a fonte do resultado por plataforma que a tela /agenda mostra (posted /
 * parcial / failed com motivo). Posts manuais/legados ficam com slot null —
 * o unique permite múltiplos NULLs no MySQL.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('social_posts', function (Blueprint $table): void {
            $table->foreignId('schedule_slot_id')->nullable()->after('uuid')
                ->constrained('schedule_slots')->nullOnDelete();
            $table->unique(['schedule_slot_id', 'platform']);
        });
    }

    public function down(): void
    {
        Schema::table('social_posts', function (Blueprint $table): void {
            $table->dropUnique(['schedule_slot_id', 'platform']);
            $table->dropConstrainedForeignId('schedule_slot_id');
        });
    }
};
