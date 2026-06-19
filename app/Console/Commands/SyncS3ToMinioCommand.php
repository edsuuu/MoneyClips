<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\S3Sync\SyncConfig;
use App\Services\S3Sync\SyncRunner;
use App\Services\S3Sync\SyncSummary;
use Closure;
use Illuminate\Console\Command;

final class SyncS3ToMinioCommand extends Command
{
    protected $signature = 's3:sync-to-minio
        {--prefix= : sobrepõe SOURCE_PATH_PREFIX}
        {--part-size=8 : tamanho de cada parte multipart em MB (mínimo 5)}
        {--force : recopia mesmo quando o objeto já existe igual}';

    protected $description = 'Sincroniza objetos de um bucket S3 remoto para o MinIO local.';

    public function handle(SyncRunner $runner): int
    {
        $partSizeMb = max(5, (int) $this->option('part-size'));
        $config = new SyncConfig(
            sourceEndpoint: (string) config('s3-sync.source.endpoint'),
            sourceRegion: (string) config('s3-sync.source.region'),
            sourceAccessKey: (string) config('s3-sync.source.access_key'),
            sourceSecretKey: (string) config('s3-sync.source.secret_key'),
            sourceBucket: (string) config('s3-sync.source.bucket'),
            sourcePrefix: $this->resolvePrefix(),
            destinationEndpoint: (string) config('s3-sync.destination.endpoint'),
            destinationRegion: (string) config('s3-sync.destination.region'),
            destinationAccessKey: (string) config('s3-sync.destination.access_key'),
            destinationSecretKey: (string) config('s3-sync.destination.secret_key'),
            destinationBucket: $this->resolveDestinationBucket(),
            concurrency: 1,
            partSizeBytes: $partSizeMb * 1024 * 1024,
            force: (bool) $this->option('force'),
        );

        $summary = $runner->run($config, $this->reporter());

        $this->renderSummary($summary);

        return $summary->failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function resolvePrefix(): string
    {
        $override = (string) ($this->option('prefix') ?? '');

        return $override !== '' ? $override : (string) config('s3-sync.source.prefix');
    }

    private function resolveDestinationBucket(): string
    {
        $bucket = (string) config('s3-sync.destination.bucket');

        return $bucket !== '' ? $bucket : (string) config('s3-sync.source.bucket');
    }

    private function reporter(): Closure
    {
        return function (string $event, array $payload): void {
            /** @var array<string, mixed> $payload */
            match ($event) {
                'header' => $this->renderHeader($payload),
                'scan_start' => $this->info('• listando objetos da origem...'),
                'scan_done' => $this->info(sprintf(
                    '%d objeto(s) na origem, %d a sincronizar.',
                    (int) ($payload['scanned'] ?? 0),
                    (int) ($payload['planned'] ?? 0),
                )),
                'copy_ok' => $this->line(sprintf(
                    '[%d/%d] OK  %s (%s)',
                    (int) ($payload['index'] ?? 0),
                    (int) ($payload['total'] ?? 0),
                    (string) ($payload['key'] ?? ''),
                    $this->humanBytes((int) ($payload['size'] ?? 0)),
                )),
                'copy_fail' => $this->error(sprintf(
                    '[%d/%d] FAIL  %s — %s',
                    (int) ($payload['index'] ?? 0),
                    (int) ($payload['total'] ?? 0),
                    (string) ($payload['key'] ?? ''),
                    (string) ($payload['message'] ?? ''),
                )),
                default => null,
            };
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function renderHeader(array $payload): void
    {
        $this->line(str_repeat('-', 64));
        $this->line('origem  : '.((string) ($payload['source'] ?? '')));
        $this->line('destino : '.((string) ($payload['destination'] ?? '')));
        $this->line('modo    : sync'.((bool) ($payload['force'] ?? false) ? ' +force' : ''));
        $this->line(str_repeat('-', 64));
    }

    private function renderSummary(SyncSummary $summary): void
    {
        $this->line(str_repeat('-', 64));
        $this->info(sprintf(
            '%d copiado(s) (%s), %d falha(s).',
            $summary->copied,
            $this->humanBytes($summary->bytesCopied),
            $summary->failed,
        ));
    }

    private function humanBytes(int $bytes): string
    {
        return number_format($bytes / 1_048_576, 1, ',', '.').' MB';
    }
}
