<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\TikTok\TikTokPostDispatcher;
use Illuminate\Console\Command;
use Throwable;

/**
 * Sorteia Shorts do estoque e enfileira postagens no TikTok via microserviço
 * tiktok-uploader. O uploader processa em série (navegador único) e grava o
 * ciclo de vida de cada post na tabela tiktok_posts deste banco.
 *
 * Roda no scheduler (routes/console.php) nos horários de engajamento.
 */
final class DispatchTiktokPosts extends Command
{
    protected $signature = 'tiktok:dispatch-posts
        {--count=1 : Quantos posts enfileirar nesta execução}';

    protected $description = 'Sorteia Shorts do estoque e enfileira postagens no TikTok (microserviço tiktok-uploader).';

    public function handle(TikTokPostDispatcher $dispatcher): int
    {
        $count = max(1, (int) $this->option('count'));
        $failures = 0;

        for ($i = 0; $i < $count; $i++) {
            try {
                $result = $dispatcher->dispatchOne();
            } catch (Throwable $e) {
                $failures++;
                $this->components->error('Falha ao enfileirar post: '.$e->getMessage());

                continue;
            }

            $label = $result['title'] ?? '(sorteio dentro do uploader)';
            $this->components->info(sprintf(
                'Post enfileirado [%s]: %s (job %s)',
                $result['source'],
                $label,
                $result['job_id'],
            ));
        }

        $this->line('  Acompanhe na tabela tiktok_posts (o uploader atualiza o status).');

        return $failures === $count ? self::FAILURE : self::SUCCESS;
    }
}
