<?php

declare(strict_types=1);

namespace App\Exceptions;

use Exception;

final class ClaudeException extends Exception
{
    public static function failed(int $exitCode, string $detail): self
    {
        if ($detail === '') {
            return new self(sprintf('O Claude falhou (exit %d) sem mensagem de erro.', $exitCode));
        }

        return new self(sprintf('O Claude falhou (exit %d): %s', $exitCode, $detail));
    }

    public static function timedOut(int $seconds): self
    {
        return new self(sprintf('O Claude não respondeu em %d segundos.', $seconds));
    }

    public static function missingStructuredOutput(): self
    {
        return new self('O Claude respondeu sem a saída estruturada.');
    }
}
