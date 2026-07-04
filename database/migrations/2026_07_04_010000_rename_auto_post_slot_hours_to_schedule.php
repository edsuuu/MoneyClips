<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A agenda deixou de ser uma lista global de horas e virou um mapa por dia
 * da semana (ISO 1=Seg..7=Dom) de horários exatos "HH:MM", editado na /agenda
 * com inputs de hora. Renomeia a coluna pra refletir a nova forma — o valor
 * em prod ainda era null, então não há dado a converter.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->renameColumn('auto_post_slot_hours', 'auto_post_schedule');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->renameColumn('auto_post_schedule', 'auto_post_slot_hours');
        });
    }
};
