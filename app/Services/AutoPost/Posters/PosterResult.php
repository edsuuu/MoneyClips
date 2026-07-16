<?php

declare(strict_types=1);

namespace App\Services\AutoPost\Posters;

/**
 * Resultado de uma tentativa de postagem em uma plataforma. Tudo é síncrono
 * do ponto de vista do Laravel: `ok` publicou; `dry-run` o serviço está em
 * modo teste; `restricted` a plataforma recusou por moderação (não repostar);
 * `failed` erro (detalhe em $error).
 */
final readonly class PosterResult
{
    /**
     * @param  'ok'|'dry-run'|'restricted'|'failed'  $outcome
     */
    private function __construct(
        public string $platform,
        public string $outcome,
        public ?string $link = null,
        public ?string $error = null,
    ) {}

    public static function ok(string $platform, ?string $link = null): self
    {
        return new self($platform, 'ok', link: $link);
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

    /** Status correspondente no ledger social_posts. */
    public function ledgerStatus(): string
    {
        return $this->outcome === 'ok' ? 'completed' : $this->outcome;
    }
}
