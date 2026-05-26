<?php

declare(strict_types=1);

namespace App\Services\SocialPublishing\Contracts;

use App\Models\ScheduledPost;
use App\Services\SocialPublishing\PublishResult;

interface SocialPublisher
{
    /** Chave da plataforma (youtube, tiktok, instagram, facebook). */
    public function key(): string;

    /** Rótulo amigável para a UI. */
    public function label(): string;

    /**
     * Publica o post na plataforma usando a conta conectada.
     * Nunca deve lançar — erros viram um PublishResult::fail com contexto.
     */
    public function publish(ScheduledPost $post): PublishResult;
}
