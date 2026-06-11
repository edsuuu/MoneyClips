<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\PostYoutubeShortJob;
use App\Models\YoutubeShort;
use App\Services\DiscordNotifier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;

/**
 * Sorteia Shorts já baixados (e ainda não postados) e cria um job de postagem
 * para cada sorteado.
 *
 * Algoritmo: conta os candidatos (COUNT), sorteia `count` posições distintas
 * dentro desse total e busca apenas os IDs sorteados (offset/limit) — sem
 * carregar a tabela inteira na memória. Dispara um job por ID. Hoje o padrão
 * é 1 job, mas a quantidade é configurável (config youtube_shorts.posting.posts_per_run
 * ou --count), para depois subir para 2, 3, etc.
 *
 * Uso: php artisan youtube:dispatch-posts [--count=N]
 */
final class DispatchYoutubePosts extends Command
{
    protected $signature = 'youtube:dispatch-posts
        {--count= : Quantos posts agendar nesta execução (padrão: config youtube_shorts.posting.posts_per_run)}';

    protected $description = 'Sorteia Shorts baixados ainda não postados e cria jobs de postagem.';

    public function handle(): int
    {
        $countOption = $this->option('count');
        $count = is_numeric($countOption)
            ? (int) $countOption
            : Config::integer('youtube_shorts.posting.posts_per_run', 1);

        if ($count < 1) {
            $this->components->error('--count precisa ser maior ou igual a 1.');

            return self::FAILURE;
        }

        // Candidatos: já baixados (têm video_path) e ainda não postados.
        $candidates = YoutubeShort::query()->availableToPost();

        $total = $candidates->count();

        if ($total === 0) {
            $this->components->warn('Nenhum Short disponível para postar.');

            return self::SUCCESS;
        }

        // Sorteia posições (offsets) distintas com base no total, sem repetir.
        $picks = min($count, $total);
        $offsets = [];
        while (count($offsets) < $picks) {
            $offsets[random_int(0, $total - 1)] = true;
        }

        $dispatched = 0;

        foreach (array_keys($offsets) as $offset) {
            // Busca só o ID na posição sorteada (1 linha), em ordem estável.
            $shortId = (clone $candidates)
                ->orderBy('id')
                ->offset($offset)
                ->value('id');

            if (! is_numeric($shortId)) {
                continue;
            }

            dispatch(new PostYoutubeShortJob((int) $shortId));
            $dispatched++;

            $this->line('  • Job de postagem criado para o Short ID '.$shortId);
        }

        $this->components->info('Jobs de postagem criados: '.$dispatched);

        $this->warnIfLowStock();

        return self::SUCCESS;
    }

    /**
     * Avisa no Discord quando o estoque de Shorts ainda não postados cai até o
     * limiar configurado (ex.: 20%). Throttle de 1x por dia para não repetir o
     * aviso a cada execução agendada.
     */
    private function warnIfLowStock(): void
    {
        $downloaded = YoutubeShort::query()->whereNotNull('video_path');

        $total = (clone $downloaded)->count();
        if ($total === 0) {
            return;
        }

        $remaining = (clone $downloaded)->whereNull('posted_at')->count();
        $threshold = Config::float('youtube_shorts.posting.low_stock_threshold', 0.20);

        if ($remaining / $total > $threshold) {
            return;
        }

        // Só avisa uma vez por dia.
        if (! Cache::add('youtube:low-stock-warned', true, now()->endOfDay())) {
            return;
        }

        $percent = (int) round(($remaining / $total) * 100);

        resolve(DiscordNotifier::class)->warning(
            '⚠️ Estoque de Shorts baixo',
            "Restam {$remaining} de {$total} Shorts ({$percent}%) para postar.\n".
            'Baixe mais com: php artisan youtube:download-shorts "<url-do-canal>"',
        );
    }
}
