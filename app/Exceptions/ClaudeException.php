<?php

declare(strict_types=1);

namespace App\Exceptions;

use Exception;

final class ClaudeException extends Exception
{
    public static function failed(): self
    {
        return new self('A IA não conseguiu responder agora. Tente de novo em alguns minutos.');
    }

    public static function usageLimit(): self
    {
        return new self('A IA atingiu o limite de uso da assinatura. Tente de novo depois que o limite renovar.');
    }

    public static function timedOut(): self
    {
        return new self('A IA demorou demais para responder. Tente de novo.');
    }

    public static function missingStructuredOutput(): self
    {
        return new self('A IA respondeu fora do formato esperado. Tente de novo.');
    }
}
