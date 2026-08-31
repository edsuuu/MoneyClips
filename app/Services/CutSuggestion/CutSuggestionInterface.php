<?php

declare(strict_types=1);

namespace App\Services\CutSuggestion;

use App\Models\Video;

/**
 * Ponto único de contato com a IA. A implementação real (Gemini, Claude, o que
 * for) entra por bind no container — nada mais no fluxo conhece o provedor.
 *
 * O que sai daqui NÃO é confiável: duração, ordem e sobreposição são reimpostas
 * depois pelo CutSuggestionValidatorService.
 */
interface CutSuggestionInterface
{
    /**
     * @param  array<mixed>  $transcript  shape do media: {segments: [{start, end, text, words: [...]}], language}
     * @return list<CutSuggestionData>
     */
    public function suggest(Video $video, array $transcript, string $prompt): array;
}
