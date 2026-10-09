<?php

declare(strict_types=1);

namespace App\Enums;

enum PostStatusEnum: string
{
    case Scheduled = 'scheduled';
    case Posting = 'posting';
    case Published = 'published';
    case Failed = 'failed';
    case Missed = 'missed';
    case Canceled = 'canceled';

    public function label(): string
    {
        return match ($this) {
            self::Scheduled => 'Agendado',
            self::Posting => 'Postando…',
            self::Published => 'Postado',
            self::Failed => 'Falhou',
            self::Missed => 'Perdeu o horário',
            self::Canceled => 'Cancelado',
        };
    }
}
