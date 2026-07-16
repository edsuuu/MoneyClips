<?php

declare(strict_types=1);

namespace App\Services\AutoPost\Posters;

/**
 * Contrato de um poster por plataforma. Cada implementação encapsula a
 * comunicação com a plataforma (YouTube Data API, microserviço TikTok,
 * Graph API, ...) e devolve um PosterResult uniforme pro job de postagem.
 *
 * Todos os posters são SÍNCRONOS do ponto de vista do chamador — quem dá a
 * assincronia é a fila (PostSlotToPlatform roda na queue `posting`).
 */
interface PosterContract
{
    /** Identificador da plataforma — casa com platform_settings.platform. */
    public function platform(): string;

    /** Toggle global da plataforma (PlatformSetting::isEnabled). */
    public function isEnabled(): bool;

    public function post(PostTask $task): PosterResult;
}
