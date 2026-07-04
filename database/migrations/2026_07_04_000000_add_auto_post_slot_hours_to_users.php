<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * As horas dos slots da auto-postagem saem do código (constante do
 * WindowSchedule) e passam a viver no banco, editáveis pela /agenda sem
 * deploy — horário fixo todo dia é padrão que as plataformas reconhecem.
 * Null = usa o default (9/12/15/18/21). Mesma convenção dos toggles
 * auto_post_*_enabled: o valor do 1º user (admin) é a fonte de verdade.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->json('auto_post_slot_hours')->nullable()->after('auto_post_tiktok_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('auto_post_slot_hours');
        });
    }
};
