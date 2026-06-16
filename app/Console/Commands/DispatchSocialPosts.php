<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\PostYoutubeShortJob;
use App\Models\YoutubeShort;
use App\Services\DiscordNotifier;
use App\Services\TiktokPost\TiktokPostService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Sorteia 1 Short do estoque (fonte única: youtube_shorts) e publica o MESMO
 * vídeo no YouTube (Data API) e no TikTok (microserviço uploader).
 *
 * Não-repetição: o Short é RESERVADO no instante do sorteio (dispatched_at),
 * saindo do pool na hora — mesmo que uma das postagens falhe depois (o vídeo
 * é "queimado", não re-sorteado automaticamente).
 *
 * Roda pelo scheduler (routes/console.php) em 5 janelas/dia, cada uma num
 * minuto aleatório (ver App\Support\PostingSchedule).
 */
final class DispatchSocialPosts extends Command
{
    protected $signature = 'social:dispatch-posts
        {--count=1 : Quantos vídeos sortear/publicar nesta execução}';

    protected $description = 'Sorteia 1 Short e publica o MESMO vídeo no YouTube e no TikTok.';

    public function handle(TiktokPostService $tiktok, DiscordNotifier $discord): int
    {
        $count = max(1, (int) $this->option('count'));

        for ($i = 0; $i < $count; $i++) {
            $short = $this->reserveNext();

            if ($short === null) {
                $this->components->warn('Nenhum Short disponível para postar.');
                break;
            }

            // YouTube: assíncrono, via job com retry próprio. Marca posted_youtube_at no sucesso.
            dispatch(new PostYoutubeShortJob($short->id));

            // TikTok: enfileira no uploader; o callback grava posted_tiktok_at no sucesso.
            try {
                $tiktok->queuePost(
                    $short->youtube_id,
                    $short->title ?? $short->youtube_id,
                    $short->hashtags ?? [],
                );
            } catch (Throwable $e) {
                $this->components->error('Falha ao enfileirar no TikTok: '.$e->getMessage());
                $discord->error(
                    '❌ Falha ao enfileirar no TikTok',
                    ($short->title ?? $short->youtube_id).PHP_EOL.$e->getMessage(),
                );
            }

            $this->components->info(sprintf(
                'Publicado [%s] nos dois canais (Short ID %d).',
                $short->title ?? $short->youtube_id,
                $short->id,
            ));
        }

        $this->warnIfLowStock($discord);

        return self::SUCCESS;
    }

    /**
     * Sorteia e RESERVA o próximo Short disponível de forma atômica
     * (lockForUpdate evita que duas execuções concorrentes peguem o mesmo).
     */
    private function reserveNext(): ?YoutubeShort
    {
        return DB::transaction(function (): ?YoutubeShort {
            $short = YoutubeShort::query()
                ->availableToPost()
                ->inRandomOrder()
                ->lockForUpdate()
                ->first();

            if ($short === null) {
                return null;
            }

            $short->forceFill(['dispatched_at' => now()])->save();

            return $short;
        });
    }

    /**
     * Avisa no Discord 1x/dia quando o estoque disponível cai até o limiar.
     */
    private function warnIfLowStock(DiscordNotifier $discord): void
    {
        $total = YoutubeShort::query()->whereNotNull('video_path')->count();
        if ($total === 0) {
            return;
        }

        $remaining = YoutubeShort::query()->availableToPost()->count();
        $threshold = Config::float('youtube_shorts.posting.low_stock_threshold', 0.20);

        if ($remaining / $total > $threshold) {
            return;
        }

        // Só avisa uma vez por dia.
        if (! Cache::add('social:low-stock-warned', true, now()->endOfDay())) {
            return;
        }

        $percent = (int) round(($remaining / $total) * 100);

        $discord->warning(
            '⚠️ Estoque de Shorts baixo',
            "Restam {$remaining} de {$total} Shorts ({$percent}%) para postar.\n".
            'Baixe mais com: php artisan youtube:download-shorts "<url-do-canal>"',
        );
    }
}
