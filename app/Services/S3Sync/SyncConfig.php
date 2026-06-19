<?php

declare(strict_types=1);

namespace App\Services\S3Sync;

final readonly class SyncConfig
{
    public function __construct(
        public string $sourceEndpoint,
        public string $sourceRegion,
        public string $sourceAccessKey,
        public string $sourceSecretKey,
        public string $sourceBucket,
        public string $sourcePrefix,
        public string $destinationEndpoint,
        public string $destinationRegion,
        public string $destinationAccessKey,
        public string $destinationSecretKey,
        public string $destinationBucket,
        public int $concurrency,
        public int $partSizeBytes,
        public bool $force,
    ) {}
}
