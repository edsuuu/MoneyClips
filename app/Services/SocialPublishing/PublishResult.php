<?php

declare(strict_types=1);

namespace App\Services\SocialPublishing;

/**
 * Resultado de uma tentativa de publicação em uma plataforma.
 */
final readonly class PublishResult
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public bool $success,
        public ?string $externalId = null,
        public ?string $url = null,
        public string $message = '',
        public array $context = [],
    ) {}

    /**
     * @param  array<string, mixed>  $context
     */
    public static function ok(?string $externalId = null, ?string $url = null, string $message = 'Publicado com sucesso', array $context = []): self
    {
        return new self(true, $externalId, $url, $message, $context);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public static function fail(string $message, array $context = []): self
    {
        return new self(false, null, null, $message, $context);
    }
}
