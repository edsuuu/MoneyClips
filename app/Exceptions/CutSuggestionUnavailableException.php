<?php

declare(strict_types=1);

namespace App\Exceptions;

use Exception;

/**
 * Sem render() de propósito: essa falha nunca fecha uma request HTTP — ela
 * derruba o job de sugestão, que vira toast e alerta no Discord.
 */
final class CutSuggestionUnavailableException extends Exception
{
    public static function notConfigured(): self
    {
        return new self(
            'Nenhum provedor de IA configurado: implemente CutSuggestionInterface e registre o bind no AppServiceProvider.',
        );
    }
}
