<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Video;
use App\Services\HLS\VideoStatusEnum;
use App\Services\Upload\MultipartUploadInterface;
use Illuminate\Console\Command;
use Throwable;

/**
 * Aba fechada no meio de um envio deixa o multipart aberto no MinIO ocupando
 * espaço em silêncio — o bucket não mostra partes órfãs numa listagem comum.
 */
final class PruneStaleUploadsCommand extends Command
{
    private const int STALE_HOURS = 24;

    /** @var string */
    protected $signature = 'uploads:prune-stale';

    /** @var string */
    protected $description = 'Aborta uploads multipart abandonados há mais de 24h e remove as linhas órfãs.';

    public function handle(MultipartUploadInterface $uploads): int
    {
        $stale = Video::query()
            ->where('status', VideoStatusEnum::AwaitingUpload)
            ->where('created_at', '<', now()->subHours(self::STALE_HOURS))
            ->get();

        if ($stale->isEmpty()) {
            $this->info('Nenhum upload abandonado.');

            return self::SUCCESS;
        }

        foreach ($stale as $video) {
            if ($video->upload_id !== null) {
                try {
                    $uploads->abort($video->path(), $video->upload_id);
                } catch (Throwable $exception) {
                    $this->warn(sprintf('Falha ao abortar %s: %s', $video->uuid, $exception->getMessage()));
                }
            }

            $video->delete();
            $this->line(sprintf('Removido: %s', $video->uuid));
        }

        $this->info(sprintf('%d upload(s) abandonado(s) removido(s).', $stale->count()));

        return self::SUCCESS;
    }
}
