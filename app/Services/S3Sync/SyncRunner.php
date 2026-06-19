<?php

declare(strict_types=1);

namespace App\Services\S3Sync;

use Aws\Exception\AwsException;
use Aws\S3\S3Client;
use Closure;
use Throwable;

/**
 * Orquestra a sincronização: monta os dois clients S3, garante o bucket de
 * destino, lista a origem paginadamente, decide o que copiar via ObjectPlanner
 * e dispara as cópias via ObjectCopier.
 */
final class SyncRunner
{
    /**
     * @param  Closure(string $event, array<string, mixed> $payload): void  $reporter
     */
    public function run(SyncConfig $config, Closure $reporter): SyncSummary
    {
        $source = $this->buildClient(
            $config->sourceEndpoint,
            $config->sourceRegion,
            $config->sourceAccessKey,
            $config->sourceSecretKey,
        );

        $destination = $this->buildClient(
            $config->destinationEndpoint,
            $config->destinationRegion,
            $config->destinationAccessKey,
            $config->destinationSecretKey,
        );

        $this->ensureBucket($destination, $config->destinationBucket);

        $planner = new ObjectPlanner($destination);
        $copier = new ObjectCopier($source, $destination);

        $reporter('header', [
            'source' => sprintf('%s/%s/%s', $config->sourceEndpoint, $config->sourceBucket, $config->sourcePrefix),
            'destination' => sprintf('%s/%s', $config->destinationEndpoint, $config->destinationBucket),
            'force' => $config->force,
        ]);

        $plan = $this->buildPlan($source, $config, $planner, $reporter);
        if ($plan === []) {
            return new SyncSummary(scanned: 0, copied: 0, failed: 0, bytesCopied: 0);
        }

        return $this->execute($plan, $config, $copier, $reporter);
    }

    /**
     * @param  Closure(string, array<string, mixed>): void  $reporter
     * @return list<array<string, mixed>>
     */
    private function buildPlan(
        S3Client $source,
        SyncConfig $config,
        ObjectPlanner $planner,
        Closure $reporter,
    ): array {
        $reporter('scan_start', []);

        /** @var list<array<string, mixed>> $plan */
        $plan = [];
        $scanned = 0;
        $paginator = $source->getPaginator('ListObjectsV2', [
            'Bucket' => $config->sourceBucket,
            'Prefix' => $config->sourcePrefix !== '' ? $config->sourcePrefix : null,
        ]);

        foreach ($paginator as $page) {
            $contents = is_array($page['Contents'] ?? null) ? $page['Contents'] : [];
            foreach ($contents as $object) {
                if (! is_array($object)) {
                    continue;
                }

                /** @var array<string, mixed> $object */
                if (str_ends_with((string) ($object['Key'] ?? ''), '/')) {
                    continue;
                }

                $scanned++;
                if ($planner->needsCopy($object, $config->destinationBucket, $config->force)) {
                    $plan[] = $object;
                }
            }
        }

        $reporter('scan_done', ['scanned' => $scanned, 'planned' => count($plan)]);

        return $plan;
    }

    /**
     * @param  list<array<string, mixed>>  $plan
     * @param  Closure(string, array<string, mixed>): void  $reporter
     */
    private function execute(array $plan, SyncConfig $config, ObjectCopier $copier, Closure $reporter): SyncSummary
    {
        $total = count($plan);
        $copied = 0;
        $failed = 0;
        $bytesCopied = 0;

        foreach ($plan as $index => $object) {
            $key = (string) $object['Key'];
            $size = (int) ($object['Size'] ?? 0);

            try {
                $copier->copy($key, $config->sourceBucket, $config->destinationBucket, $config->partSizeBytes);
                $copied++;
                $bytesCopied += $size;
                $reporter('copy_ok', ['index' => $index + 1, 'total' => $total, 'key' => $key, 'size' => $size]);
            } catch (Throwable $exception) {
                $failed++;
                $reporter('copy_fail', [
                    'index' => $index + 1,
                    'total' => $total,
                    'key' => $key,
                    'message' => $exception->getMessage(),
                ]);
            }
        }

        return new SyncSummary(scanned: $total, copied: $copied, failed: $failed, bytesCopied: $bytesCopied);
    }

    private function buildClient(string $endpoint, string $region, string $accessKey, string $secretKey): S3Client
    {
        return new S3Client([
            'version' => 'latest',
            'region' => $region,
            'endpoint' => $endpoint,
            'use_path_style_endpoint' => true,
            'credentials' => [
                'key' => $accessKey,
                'secret' => $secretKey,
            ],
        ]);
    }

    private function ensureBucket(S3Client $client, string $bucket): void
    {
        try {
            $client->headBucket(['Bucket' => $bucket]);
        } catch (AwsException) {
            $client->createBucket(['Bucket' => $bucket]);
        }
    }
}
