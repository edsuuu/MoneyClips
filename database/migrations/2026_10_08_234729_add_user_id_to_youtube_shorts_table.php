<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('youtube_shorts', function (Blueprint $table): void {
            $table->foreignId('user_id')->nullable()->after('id')->constrained()->nullOnDelete();
        });

        $this->backfillOwnerFromEditedCut();
    }

    /**
     * O short de corte editado herda o dono do vídeo longo (video_cuts_edits →
     * video_cuts → videos.user_id). Short baixado de canal não tem dono e fica
     * null (só admin enxerga). Público porque o teste chama direto: no sqlite
     * da suíte o ALTER recria a tabela dentro da transação e o PRAGMA
     * foreign_keys não desliga, zerando o youtube_short_id das edições.
     */
    public function backfillOwnerFromEditedCut(): void
    {
        DB::table('youtube_shorts')->whereNull('user_id')->update([
            'user_id' => DB::raw(<<<'SQL'
                (select videos.user_id from video_cuts_edits
                    join video_cuts on video_cuts.id = video_cuts_edits.video_cut_id
                    join videos on videos.id = video_cuts.video_id
                    where video_cuts_edits.youtube_short_id = youtube_shorts.id
                    limit 1)
                SQL),
        ]);
    }

    public function down(): void
    {
        Schema::table('youtube_shorts', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('user_id');
        });
    }
};
