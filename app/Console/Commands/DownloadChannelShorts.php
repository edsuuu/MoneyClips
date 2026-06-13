<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Shorts\ShortsDownloaderClient;
use App\Support\Cast;
use Illuminate\Console\Command;
use Illuminate\Support\Sleep;
use Throwable;

/**
 * Cria um job no microserviço Python download-shorts para baixar todos os
 * Shorts de um canal. O microserviço lista, baixa, sobe para o storage
 * S3-compatible (Contabo) e guarda os itens no banco DELE — nada é enviado
 * de volta ao Laravel quando o lote termina (dispatch_on_complete=false).
 *
 * Uso: php artisan youtube:download-shorts "https://www.youtube.com/@canal"
 */
final class DownloadChannelShorts extends Command
{
    /** Status do job que indicam que o microserviço ainda está trabalhando. */
    private const array RUNNING_STATUSES = ['queued', 'listing', 'processing'];

    protected $signature = 'youtube:download-shorts
        {channel : URL do canal do YouTube}
        {--no-wait : Apenas cria o job, sem acompanhar o progresso}
        {--poll=5 : Intervalo (segundos) entre consultas de status}';

    protected $description = 'Envia um canal ao microserviço download-shorts, que baixa os Shorts para o storage (Contabo) e mantém tudo no banco dele.';

    public function handle(ShortsDownloaderClient $client): int
    {
        $channel = (string) $this->argument('channel');

        if (! $client->isHealthy()) {
            $this->components->error('Microserviço download-shorts indisponível. Suba-o (porta 8770) e tente de novo.');

            return self::FAILURE;
        }

        try {
            $jobId = $client->createJob($channel);
        } catch (Throwable $throwable) {
            $this->components->error('Falha ao criar o job: '.$throwable->getMessage());

            return self::FAILURE;
        }

        $this->components->info(sprintf('Job criado no microserviço: %s', $jobId));

        if ((bool) $this->option('no-wait')) {
            $this->components->info(sprintf('Acompanhe com: curl %s/shorts/download/%s', Cast::str(config('shorts-downloader.base_url')), $jobId));

            return self::SUCCESS;
        }

        return $this->watch($client, $jobId);
    }

    /** Consulta o status do job em loop até o microserviço finalizar o lote. */
    private function watch(ShortsDownloaderClient $client, string $jobId): int
    {
        $poll = max(1, Cast::int($this->option('poll')));
        $bar = null;
        $status = [];

        while (true) {
            try {
                $status = $client->jobStatus($jobId);
            } catch (Throwable $e) {
                $this->components->warn('Falha ao consultar status (tentando de novo): '.$e->getMessage());
                Sleep::sleep($poll);

                continue;
            }

            $state = Cast::str($status['status'] ?? '');
            $total = Cast::int($status['total'] ?? 0);
            $done = Cast::int($status['completed'] ?? 0) + Cast::int($status['failed'] ?? 0);

            if ($bar === null && $total > 0) {
                $bar = $this->output->createProgressBar($total);
                $bar->setFormat(' %current%/%max% [%bar%] %percent:3s%%');
                $bar->start();
            }

            if ($bar !== null) {
                $bar->setProgress(min($done, $total));
            }

            if (! in_array($state, self::RUNNING_STATUSES, true)) {
                break;
            }

            Sleep::sleep($poll);
        }

        if ($bar !== null) {
            $bar->finish();
            $this->newLine(2);
        }

        return $this->summarize($jobId, $status);
    }

    /** @param array<string, mixed> $status */
    private function summarize(string $jobId, array $status): int
    {
        $state = Cast::str($status['status'] ?? '');
        $completed = Cast::int($status['completed'] ?? 0);
        $failed = Cast::int($status['failed'] ?? 0);
        $total = Cast::int($status['total'] ?? 0);

        $this->components->info(sprintf(
            'Job %s finalizado com status "%s": %d/%d baixados | %d falhas.',
            $jobId,
            $state,
            $completed,
            $total,
            $failed,
        ));
        $this->line('  Os vídeos estão no storage (Contabo) e os metadados no banco do microserviço.');

        $lastError = Cast::str($status['last_error'] ?? '');
        if ($lastError !== '') {
            $this->components->warn('Último erro registrado: '.$lastError);
        }

        return $state === 'failed' ? self::FAILURE : self::SUCCESS;
    }
}
