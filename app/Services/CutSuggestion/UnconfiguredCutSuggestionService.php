<?php

declare(strict_types=1);

namespace App\Services\CutSuggestion;

use App\Exceptions\CutSuggestionUnavailableException;
use App\Models\Video;

/**
 * Implementação-padrão enquanto a integração com a IA não existe. Falha alto e
 * explícito em vez de devolver lista vazia — sugestão que "não achou nada" e
 * provedor ausente são problemas diferentes, e confundir os dois esconde a
 * configuração faltando.
 *
 * Troque o bind em AppServiceProvider pela implementação real.
 */
final readonly class UnconfiguredCutSuggestionService implements CutSuggestionInterface
{
    /**
     * @param  array<mixed>  $transcript
     * @return list<CutSuggestionData>
     *
     * @throws CutSuggestionUnavailableException
     */
    public function suggest(Video $video, array $transcript, string $prompt): array
    {
        throw CutSuggestionUnavailableException::notConfigured();
    }
}
