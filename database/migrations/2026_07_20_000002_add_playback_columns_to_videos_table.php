<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ciclo de vida do upload direto (browser → MinIO) e do empacotamento HLS.
 *
 * `hash` deixa de ser NOT NULL porque o md5 saiu do PHP: em multipart o ETag do
 * S3 é `md5-dos-md5s-N` e não serve para deduplicar, então o hash real passa a
 * ser calculado pelo microserviço de HLS (que já lê o arquivo inteiro) e chega
 * pelo webhook — depois da linha existir.
 *
 * `hls_path` guarda um PREFIXO (`hls/{uuid}/`), não um arquivo: o empacotamento
 * produz master + N media playlists + milhares de segmentos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('videos', function (Blueprint $table): void {
            $table->string('hash', 32)->nullable()->change();

            $table->string('status', 32)->default('awaiting_upload')->after('mime_type');
            $table->string('upload_id')->nullable()->after('status');
            $table->uuid('hls_remote_id')->nullable()->after('upload_id');
            $table->unsignedTinyInteger('progress')->default(0)->after('hls_remote_id');
            $table->unsignedInteger('duration_seconds')->nullable()->after('progress');
            $table->unsignedSmallInteger('width')->nullable()->after('duration_seconds');
            $table->unsignedSmallInteger('height')->nullable()->after('width');
            $table->string('hls_path')->nullable()->after('height');
            $table->string('poster_path')->nullable()->after('hls_path');
            $table->json('renditions')->nullable()->after('poster_path');
            $table->text('error')->nullable()->after('renditions');
            $table->timestamp('ready_at')->nullable()->after('error');

            // `status` vem antes de propósito: com `user_id` na frente o MySQL
            // adota o índice para satisfazer a FK de `user_id` e passa a
            // recusar o drop (erro 1553), além de deixá-lo órfão no rollback.
            $table->index(['status', 'user_id']);
            $table->index('hls_remote_id');
        });
    }

    public function down(): void
    {
        Schema::table('videos', function (Blueprint $table): void {
            $table->dropIndex(['status', 'user_id']);

            $table->dropColumn([
                'status',
                'upload_id',
                'hls_remote_id',
                'progress',
                'duration_seconds',
                'width',
                'height',
                'hls_path',
                'poster_path',
                'renditions',
                'error',
                'ready_at',
            ]);

            $table->string('hash', 32)->nullable(false)->change();
        });
    }
};
