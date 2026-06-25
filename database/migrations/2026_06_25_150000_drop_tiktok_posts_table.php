<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Drop da tabela legada tiktok_posts. Pré-requisito: rodar
 * `php artisan posts:migrate-tiktok` antes — esta migration aborta com erro
 * se detectar linhas em tiktok_posts que NÃO foram copiadas pra social_posts.
 * Sem perda de dados acidental.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('tiktok_posts')) {
            return;
        }

        $source = (int) DB::table('tiktok_posts')->count();
        $copied = (int) DB::table('social_posts')->where('platform', 'tiktok')->count();

        if ($copied < $source) {
            throw new RuntimeException(sprintf(
                'tiktok_posts tem %d linhas mas social_posts (platform=tiktok) tem só %d. '.
                'Rode `php artisan posts:migrate-tiktok` antes desta migration.',
                $source,
                $copied,
            ));
        }

        Schema::drop('tiktok_posts');
    }

    public function down(): void
    {
        // Sem rollback automático: a tabela seria recriada vazia, mas
        // os dados ficariam só em social_posts. Reverter de verdade
        // significa reverter o PR inteiro via git.
    }
};
