<?php

declare(strict_types=1);

namespace App\Services\CutSuggestion;

/**
 * Reimpõe as regras de corte sobre o que a IA devolveu: ela erra aritmética,
 * inventa timestamp fora do vídeo e devolve trecho sobreposto. Nada do que sai
 * daqui depende de o modelo ter obedecido ao prompt.
 *
 * Guloso por score: o teto fica com os melhores (não com os primeiros no
 * tempo) e, entre dois cortes que se encostam, sobrevive o de maior score.
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

        usort($bounded, static fn (CutSuggestionData $a, CutSuggestionData $b): int => [$b->score, $a->start] <=> [$a->score, $b->start]);

        $accepted = $this->pickBest($bounded);

        usort($accepted, static fn (CutSuggestionData $a, CutSuggestionData $b): int => $a->start <=> $b->start);

        return $accepted;
    }

    /**
     * @param  list<CutSuggestionData>  $suggestions
     * @return list<CutSuggestionData>
     */
    private function applyBounds(array $suggestions, float $videoDuration): array
    {
        $minDuration = (float) config('services.cut_suggestion.min_duration', 70);
        $maxDuration = (float) config('services.cut_suggestion.max_duration', 170);

        $bounded = [];

        foreach ($suggestions as $suggestion) {
            $start = max(0.0, $suggestion->start);
            $end = min($videoDuration, $suggestion->end);

            if ($end - $start < $minDuration) {
                continue;
            }

            if ($end - $start > $maxDuration) {
                continue;
            }

            $bounded[] = $suggestion->withBounds($start, $end);
        }

        return $bounded;
    }

    /**
     * @param  list<CutSuggestionData>  $byScore
     * @return list<CutSuggestionData>
     */
    private function pickBest(array $byScore): array
    {
        $minGap = (float) config('services.cut_suggestion.min_gap', 0.0);
        $maxCuts = (int) config('services.cut_suggestion.max_cuts', 20);

        $accepted = [];

        foreach ($byScore as $suggestion) {
            if (count($accepted) >= $maxCuts) {
                break;
            }

            foreach ($accepted as $kept) {
                if ($suggestion->start < $kept->end + $minGap && $kept->start < $suggestion->end + $minGap) {
                    continue 2;
                }
            }

            $accepted[] = $suggestion;
        }

        return $accepted;
    }
}
