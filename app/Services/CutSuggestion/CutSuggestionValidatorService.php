<?php

declare(strict_types=1);

namespace App\Services\CutSuggestion;

/**
 * Reimpõe as regras de corte sobre o que a IA devolveu: ela erra aritmética,
 * inventa timestamp fora do vídeo e devolve trecho sobreposto. Nada do que sai
 * daqui depende de o modelo ter obedecido ao prompt.
 *
 * Entre dois cortes que se encostam, sobrevive o de maior score — é o critério
 * do pipeline antigo, mantido de propósito.
 */
final readonly class CutSuggestionValidatorService
{
    /**
     * @param  list<CutSuggestionData>  $suggestions
     * @return list<CutSuggestionData>
     */
    public function validate(array $suggestions, float $videoDuration): array
    {
        $bounded = $this->applyBounds($suggestions, $videoDuration);

        usort($bounded, static fn (CutSuggestionData $a, CutSuggestionData $b): int => $a->start <=> $b->start);

        return array_slice($this->applyGap($bounded), 0, $this->maxCuts());
    }

    /**
     * @param  list<CutSuggestionData>  $suggestions
     * @return list<CutSuggestionData>
     */
    private function applyBounds(array $suggestions, float $videoDuration): array
    {
        $minDuration = (float) config('services.cut_suggestion.min_duration', 60);
        $maxDuration = (float) config('services.cut_suggestion.max_duration', 80);

        $bounded = [];

        foreach ($suggestions as $suggestion) {
            $start = max(0.0, $suggestion->start);
            $end = min($videoDuration, $suggestion->end);

            if ($end - $start < $minDuration) {
                continue;
            }

            if ($end - $start > $maxDuration) {
                $end = $start + $maxDuration;
            }

            $bounded[] = $suggestion->withBounds($start, $end);
        }

        return $bounded;
    }

    /**
     * @param  list<CutSuggestionData>  $suggestions
     * @return list<CutSuggestionData>
     */
    private function applyGap(array $suggestions): array
    {
        $minGap = (float) config('services.cut_suggestion.min_gap', 1.0);

        $ordered = [];

        foreach ($suggestions as $suggestion) {
            $last = $ordered === [] ? null : $ordered[count($ordered) - 1];

            if ($last instanceof CutSuggestionData && $suggestion->start < $last->end + $minGap) {
                if ($suggestion->score > $last->score) {
                    $ordered[count($ordered) - 1] = $suggestion;
                }

                continue;
            }

            $ordered[] = $suggestion;
        }

        return $ordered;
    }

    private function maxCuts(): int
    {
        return (int) config('services.cut_suggestion.max_cuts', 20);
    }
}
