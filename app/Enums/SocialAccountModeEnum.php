<?php

declare(strict_types=1);

namespace App\Enums;

enum SocialAccountModeEnum: string
{
    case Off = 'off';
    case Manual = 'manual';
    case Auto = 'auto';

    public function label(): string
    {
        return match ($this) {
            self::Off => 'Desligada',
            self::Manual => 'Manual',
            self::Auto => 'Automática',
        };
    }

    public function help(): string
    {
        return match ($this) {
            self::Off => 'Não aparece na hora de agendar.',
            self::Manual => 'Você escolhe quando cada Short sai.',
            self::Auto => 'Todo Short pronto é agendado sozinho nos horários bons.',
        };
    }
}
