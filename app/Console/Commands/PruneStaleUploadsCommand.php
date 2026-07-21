<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Video;
use App\Services\Api\Discord\DiscordNotifierService;
use App\Services\HLS\VideoStatusEnum;
use App\Services\Upload\MultipartUploadInterface;
use Illuminate\Console\Command;
use Throwable;

/**
 * Aba fechada no meio de um envio deixa o multipart aberto no MinIO ocupando
 * espaço em silêncio — o bucket não mostra partes órfãs numa listagem comum.
 *
 * E o empacotamento HLS é assíncrono: o job marca `packaging` e sai, quem
 * fecha é o webhook. Se o microserviço morrer no meio, ninguém fecha — a linha
 * fica pendente pra sempre e a tela fica pollando a cada 5s sem nunca parar.
 */
final class PruneStaleUploadsCommand extends Command
{
    private const int STALE_HOURS = 24;

    /**
     * Durante o encode o serviço manda progresso a cada ~10s, e cada avanço
     * toca o `updated_at` — então silêncio longo é morte, não lentidão. A folga
     * cobre estol de fila/GPU sem matar job vivo.
     */
    private const int SILENT_HOURS = 6;

    /** @var string */
    protected $signature = 'uploads:prune-stale';

    /** @var string */
    protected $description = 'Aborta uploads multipart abandonados há mais de 24h e falha empacotamentos travados.';

    public function handle(MultipartUploadInterface $uploads, DiscordNotifierService $discord): int
    {
        $this->pruneAbandoned($uploads);
        $this->failStalledPackaging($discord);

        return self::SUCCESS;
    }

    private function pruneAbandoned(MultipartUploadInterface $uploads): void
    {
        $stale = Video::query()
            ->where('status', VideoStatusEnum::AwaitingUpload)
            ->where('created_at', '<', now()->subHours(self::STALE_HOURS))
            ->get();

        if ($stale->isEmpty()) {
            $this->info('Nenhum upload abandonado.');

            return;
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
    }

    /**
     * O binário no MinIO continua intacto — por isso marca `failed` em vez de
     * apagar: o operador reenvia ou reprocessa.
     */
    private function failStalledPackaging(DiscordNotifierService $discord): void
    {
        $stalled = Video::query()
            ->whereIn('status', [VideoStatusEnum::Uploaded, VideoStatusEnum::Packaging])
            ->where('updated_at', '<', now()->subHours(self::SILENT_HOURS))
            ->get();

        if ($stalled->isEmpty()) {
            $this->info('Nenhum empacotamento travado.');

            return;
        }

        foreach ($stalled as $video) {
            $error = sprintf(
                'Empacotamento sem sinal há mais de %dh (parou em %d%%). O serviço de vídeo pode ter caído antes do webhook.',
                self::SILENT_HOURS,
                $video->progress,
            );

            $video->forceFill([
                'status' => VideoStatusEnum::Failed,
                'error' => $error,
            ])->save();

            $this->warn(sprintf('Travado: %s', $video->uuid));

            $discord->error(
                '❌ Empacotamento HLS travado',
                sprintf('Vídeo %s%s%s', $video->uuid, PHP_EOL, $error),
            );
        }

        $this->info(sprintf('%d empacotamento(s) travado(s) marcado(s) como falha.', $stalled->count()));
    }
}
