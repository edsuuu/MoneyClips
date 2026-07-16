<?php

declare(strict_types=1);

namespace App\Services\AutoPost;

/**
 * Constantes compartilhadas da auto-postagem (substitui o WindowSchedule,
 * que morreu junto com a agenda em users.auto_post_schedule — os horários
 * agora vivem em schedule_slots).
 */
final class AutoPost
{
    /** Fuso de negócio de toda a agenda. */
    public const string TIMEZONE = 'America/Sao_Paulo';

    /**
     * Horários default do gerador de semana quando não há semana anterior
     * nem agenda legada pra copiar. Gap de 3h — o TikTok não flagga
     * proximidade dentro desse espaço.
     *
     * @var list<string>
     */
    public const array DEFAULT_TIMES = ['09:00', '12:00', '15:00', '18:00', '21:00'];
}
