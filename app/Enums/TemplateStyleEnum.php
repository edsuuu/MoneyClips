<?php

declare(strict_types=1);

namespace App\Enums;

enum TemplateStyleEnum: string
{
    case White = 'white';
    case Black = 'black';
    case Vertical = 'vertical';

    public function variant(): string
    {
        return match ($this) {
            self::White => 'template_white',
            self::Black => 'template_black',
            self::Vertical => 'vertical',
        };
    }

    public function captionPosition(): string
    {
        return match ($this) {
            self::White, self::Black => 'below',
            self::Vertical => 'inside',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::White => 'Claro',
            self::Black => 'Escuro',
            self::Vertical => 'Vertical',
        };
    }
}
