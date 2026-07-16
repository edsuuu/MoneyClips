<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Estilos de template disponíveis no editor — cada um mapeia para um variant
 * do microserviço AutoCaption (MicroServices/AutoCaption).
 */
enum TemplateStyleEnum: string
{
    case White = 'white';
    case Black = 'black';
    case Vertical = 'vertical';

    /** Variant correspondente no AutoCaption. */
    public function variant(): string
    {
        return match ($this) {
            self::White => 'template_white',
            self::Black => 'template_black',
            self::Vertical => 'vertical',
        };
    }

    /** Posição da legenda karaokê no vídeo renderizado. */
    public function captionPosition(): string
    {
        return match ($this) {
            self::White, self::Black => 'below',
            self::Vertical => 'inside',
        };
    }

    /** Rótulo da UI (pt-BR). */
    public function label(): string
    {
        return match ($this) {
            self::White => 'Claro',
            self::Black => 'Escuro',
            self::Vertical => 'Vertical',
        };
    }
}
