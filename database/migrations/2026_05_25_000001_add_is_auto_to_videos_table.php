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
            // Quando true, o vídeo segue o pipeline automático (piloto automático):
            // transcrição -> confirmação -> geração de cortes -> renderização, sem etapas manuais.
            $table->boolean('is_auto')->default(false)->after('current_stage');
        });
    }

    public function down(): void
    {
        Schema::table('videos', function (Blueprint $table): void {
            $table->dropColumn('is_auto');
        });
    }
};
