<?php

declare(strict_types=1);

namespace App\Services\CutSuggestion;

final readonly class CutSuggestionData
{
    public function __construct(
        public float $start,
        public float $end,
        public int $score,
        public string $reason,
    ) {}

    public function duration(): float
    {
        return $this->end - $this->start;
    }

    public function withBounds(float $start, float $end): self
    {
        return new self($start, $end, $this->score, $this->reason);
    }
}
