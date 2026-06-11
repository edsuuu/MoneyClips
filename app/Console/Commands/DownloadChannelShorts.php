<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\YoutubeShort;
use App\Services\Youtube\YoutubeChannelService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Baixa os Shorts de um canal do YouTube direto pelo console, mostrando o
 * progresso (%) de cada download, e agenda as postagens ao final.
 *
 * Uso: php artisan youtube:download-shorts "https://www.youtube.com/@canal"
 */
final class DownloadChannelShorts extends Command
{
    protected $signature = 'youtube:download-shorts
        {channel : URL do canal do YouTube}
        {--limit= : Baixar apenas os N primeiros Shorts}';

    protected $description = 'Baixa os Shorts de um canal do YouTube com progresso, salvando no MinIO.';

    public function handle(YoutubeChannelService $service): int
    {
        $channel = (string) $this->argument('channel');

        $this->components->info('Listando Shorts de: '.$channel);

        $videos = $service->listShorts($channel);
        $total = count($videos);

        if ($total === 0) {
            $this->components->warn('Nenhum Short encontrado para este canal.');

            return self::FAILURE;
        }

        $limit = $this->option('limit');
        if ($limit !== null && (int) $limit > 0) {
            $videos = array_slice($videos, 0, (int) $limit);
        }

        $count = count($videos);
        $this->components->info(sprintf('Vídeos encontrados: %d. Baixando: %d.', $total, $count));

        $downloadedIds = [];
        $skipped = 0;
        $failed = 0;

        foreach ($videos as $i => $video) {
            $position = $i + 1;
            $label = $this->truncate($video['title'] !== '' ? $video['title'] : $video['id']);

            $bar = $this->output->createProgressBar(100);
            $bar->setFormat(sprintf(' [%d/%d] %%bar%% %%percent:3s%%%%  %s', $position, $count, $label));
            $bar->start();

            try {
                $result = $service->downloadShort(
                    $video['id'],
                    $video['url'],
                    function (float $percent) use ($bar): void {
                        $bar->setProgress((int) min(100, max(0, $percent)));
                    },
                );
            } catch (Throwable $e) {
                $result = null;
                $this->newLine();
                $this->components->error(sprintf('Falha em %s: %s', $video['id'], $e->getMessage()));
            }

            $bar->finish();
            $this->newLine();

            if ($result !== null) {
                $downloadedIds[] = $result['youtube_id'];
                $this->components->task(sprintf('✓ %s → %s', $result['youtube_id'], $result['video_path']), fn (): bool => true);
            } elseif (YoutubeShort::query()->where('youtube_id', $video['id'])->exists()) {
                $skipped++;
                $this->line(sprintf('  <fg=yellow>• %s já baixado, pulado.</>', $video['id']));
            } else {
                $failed++;
                $this->line(sprintf('  <fg=red>• %s falhou.</>', $video['id']));
            }
        }

        $this->newLine();
        $this->components->info('Baixados: '.count($downloadedIds).sprintf(' | Pulados: %d | Falhas: %d', $skipped, $failed));

        return self::SUCCESS;
    }

    private function truncate(string $text, int $max = 40): string
    {
        $text = mb_trim($text);

        return mb_strlen($text) > $max ? mb_substr($text, 0, $max - 1).'…' : $text;
    }
}
