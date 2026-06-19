<?php

declare(strict_types=1);

namespace App\Services\S3Sync;

final readonly class SyncSummary
{
    public function __construct(
        public int $scanned,
        public int $copied,
        public int $failed,
        public int $bytesCopied,
    ) {}
}
