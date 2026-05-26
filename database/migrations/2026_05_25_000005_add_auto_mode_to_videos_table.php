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
            // Estratégia de cortes no modo automático:
            //  auto       -> decide pela duração (<=15min sequencial, senão IA)
            //  sequential -> clipes de ~1min cobrindo o vídeo
            //  ai         -> melhores momentos via IA
            $table->string('auto_mode', 16)->default('auto')->after('is_auto');
            // Quantidade-alvo de clipes (opcional). null = automático.
            $table->unsignedSmallInteger('auto_clip_count')->nullable()->after('auto_mode');
        });
    }

    public function down(): void
    {
        Schema::table('videos', function (Blueprint $table): void {
            $table->dropColumn(['auto_mode', 'auto_clip_count']);
        });
    }
};
