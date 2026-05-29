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
            // Quando false, o Python pula o face tracking nos cortes deste vídeo
            // (vídeos sem rosto, screencast, animação, etc) — economiza tempo
            // e evita crops esquisitos onde não tem ninguém pra seguir.
            $table->boolean('face_tracking')->default(true)->after('auto_clip_count');
        });
    }

    public function down(): void
    {
        Schema::table('videos', function (Blueprint $table): void {
            $table->dropColumn('face_tracking');
        });
    }
};
