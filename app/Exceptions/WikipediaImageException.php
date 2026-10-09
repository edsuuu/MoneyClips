<?php

declare(strict_types=1);

namespace App\Exceptions;

use Exception;
use Throwable;

final class WikipediaImageException extends Exception
{
    public static function notFound(string $title): self
    {
        return new self(sprintf('"%s": artigo inexistente.', $title));
    }

    public static function disambiguation(string $title): self
    {
        return new self(sprintf('"%s": página de desambiguação.', $title));
    }

    public static function withoutFreeImage(string $title): self
    {
        return new self(sprintf('"%s": artigo sem imagem livre.', $title));
    }

    public static function restricted(string $title, string $restrictions): self
    {
        return new self(sprintf('"%s": imagem com restrição (%s).', $title, $restrictions));
    }

    public static function notFree(string $title, string $license): self
    {
        return new self(sprintf('"%s": licença não livre (%s).', $title, $license === '' ? 'sem licença' : $license));
    }

    public static function unsupportedFormat(string $title, string $extension): self
    {
        return new self(sprintf('"%s": formato de imagem não suportado (%s).', $title, $extension));
    }

    public static function rejected(string $title): self
    {
        return new self(sprintf('"%s": imagem recusada pelo dono no estoque.', $title));
    }

    public static function unavailable(string $title, Throwable $previous): self
    {
        return new self(sprintf('"%s": Wikipedia indisponível (%s).', $title, $previous->getMessage()), previous: $previous);
    }
}
