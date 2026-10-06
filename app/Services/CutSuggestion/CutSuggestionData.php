<?php

declare(strict_types=1);

namespace App\Services\CutSuggestion;

final readonly class CutSuggestionData
{
    /**
     * @param  list<string>  $hashtags
     */
    public function __construct(
        public float $start,
        public float $end,
        public int $score,
        public string $reason,
        public string $title = '',
        public array $hashtags = [],
    ) {}

    public function duration(): float
    {
        return $this->end - $this->start;
    }

    public function withBounds(float $start, float $end): self
    {
        return new self($start, $end, $this->score, $this->reason, $this->title, $this->hashtags);
    }
}
