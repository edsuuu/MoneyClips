<?php

declare(strict_types=1);

namespace App\DataTransferObjects;

/**
 * Resultado de uma tentativa de postagem em uma plataforma.
 *
 * `ok` publicou; `dry-run` o serviço está em modo teste; `queued` a
 * plataforma aceitou o job e o desfecho real chega por webhook (caso do
 * TikTok não-oficial — $externalId é o job_id que casa com o callback);
 * `restricted` a plataforma recusou por moderação (não repostar);
 * `failed` erro (detalhe em $error).
 */
final readonly class PosterResultData
{
    /**
     * @param  'ok'|'queued'|'dry-run'|'restricted'|'failed'  $outcome
     */
    private function __construct(
        public string $platform,
        public string $outcome,
        public ?string $link = null,
        public ?string $error = null,
        public ?string $externalId = null,
    ) {}

    public static function ok(string $platform, ?string $link = null): self
    {
        return new self($platform, 'ok', link: $link);
    }

    public static function queued(string $platform, string $externalId): self
    {
        return new self($platform, 'queued', externalId: $externalId);
    }

    public static function dryRun(string $platform): self
    {
        return new self($platform, 'dry-run');
    }

    public static function restricted(string $platform, ?string $detail = null): self
    {
        return new self($platform, 'restricted', error: $detail);
    }

    public static function failed(string $platform, string $error): self
    {
        return new self($platform, 'failed', error: $error);
    }

    public function succeeded(): bool
    {
        return $this->outcome === 'ok' || $this->outcome === 'dry-run';
    }

    public function isQueued(): bool
    {
        return $this->outcome === 'queued';
    }

    /** Status correspondente no ledger social_posts. */
    public function ledgerStatus(): string
    {
        return $this->outcome === 'ok' ? 'completed' : $this->outcome;
    }
}
