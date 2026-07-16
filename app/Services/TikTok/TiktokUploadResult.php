<?php

declare(strict_types=1);

namespace App\Services\TikTok;

/**
 * Resposta síncrona do microserviço tiktok-uploader (POST /posts).
 * status: completed | dry-run | restricted.
 */
final readonly class TiktokUploadResult
{
    public function __construct(
        public string $status,
        public ?string $detail = null,
    ) {}

    public function isDryRun(): bool
    {
        return $this->status === 'dry-run';
    }

    public function isRestricted(): bool
    {
        return $this->status === 'restricted';
    }
}
