<?php

declare(strict_types=1);

namespace App\Services\AutoPost\Posters;

/**
 * Resultado de uma tentativa de postagem em uma plataforma. Posters síncronos
 * (YouTube) devolvem ok(link); assíncronos (TikTok) devolvem queued() porque
 * a publicação real é confirmada depois pelo webhook do uploader.
 */
final readonly class PosterResult
{
    /**
     * @param  'ok'|'queued'|'failed'  $outcome
     */
    private function __construct(
        public string $platform,
        public string $outcome,
        public ?string $link = null,
        public ?string $error = null,
    ) {}

    public static function ok(string $platform, string $link): self
    {
        return new self($platform, 'ok', link: $link);
    }

    public static function queued(string $platform): self
    {
        return new self($platform, 'queued');
    }

    public static function failed(string $platform, string $error): self
    {
        return new self($platform, 'failed', error: $error);
    }

    public function succeeded(): bool
    {
        return $this->outcome === 'ok' || $this->outcome === 'queued';
    }
}
