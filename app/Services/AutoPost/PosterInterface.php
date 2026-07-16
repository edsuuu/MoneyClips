<?php

declare(strict_types=1);

namespace App\Services\AutoPost;

/**
 * Contrato de um poster por plataforma. Cada implementação encapsula a
 * comunicação com a plataforma (YouTube Data API, microserviço TikTok,
 * Graph API, ...) e devolve um PosterResultData uniforme pro job de postagem.
 *
 * O retorno pode ser um desfecho final (ok/dry-run/restricted/failed) ou
 * `queued` quando a plataforma processa em background e responde por webhook
 * (TikTok não-oficial). Quem dá a assincronia do disparo é a fila
 * (PostSlotToPlatformJob roda na queue `posting`).
 */
interface PosterInterface
{
    /** Identificador da plataforma — casa com platform_settings.platform. */
    public function platform(): string;

    /** Toggle global da plataforma (PlatformSetting::isEnabled). */
    public function isEnabled(): bool;

    public function post(PostTaskData $task): PosterResultData;
}
