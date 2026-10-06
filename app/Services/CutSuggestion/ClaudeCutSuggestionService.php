<?php

declare(strict_types=1);

namespace App\Services\CutSuggestion;

use App\Exceptions\ClaudeException;
use App\Helpers\Hashtags;
use App\Models\Video;
use App\Services\API\Claude\ClaudeService;
use JsonException;

final readonly class ClaudeCutSuggestionService implements CutSuggestionInterface
{
    public function __construct(private ClaudeService $claude) {}

    /**
     * @param  array<mixed>  $transcript
     * @return list<CutSuggestionData>
     *
     * @throws ClaudeException
     * @throws JsonException
     */
    public function suggest(Video $video, array $transcript, string $prompt): array
    {
        $lines = [
            sprintf('Vídeo: %s (%ds)', $video->name ?? 'sem nome', (int) $video->duration_seconds),
            'Pedido do dono: '.($prompt === '' ? 'os momentos mais engraçados' : $prompt),
            'Transcrição:',
        ];

        $segments = is_array($transcript['segments'] ?? null) ? $transcript['segments'] : [];

        foreach ($segments as $segment) {
            if (! is_array($segment)) {
                continue;
            }

            $lines[] = sprintf('[%.1f-%.1f] %s', (float) ($segment['start'] ?? 0), (float) ($segment['end'] ?? 0), mb_trim((string) ($segment['text'] ?? '')));
        }

        $output = $this->claude->structured('prompts/cut-suggestion.md', 'prompts/cut-suggestion.schema.json', implode(PHP_EOL, $lines));

        $candidates = is_array($output['candidates'] ?? null) ? $output['candidates'] : [];
        $suggestions = [];

        foreach ($candidates as $candidate) {
            if (! is_array($candidate)) {
                continue;
            }

            $suggestions[] = new CutSuggestionData(
                (float) ($candidate['start'] ?? 0),
                (float) ($candidate['end'] ?? 0),
                max(0, min(10, (int) ($candidate['score'] ?? 0))),
                (string) ($candidate['arc'] ?? ''),
                mb_substr(mb_trim((string) ($candidate['title'] ?? '')), 0, 150),
                Hashtags::parse(implode(' ', array_filter((array) ($candidate['hashtags'] ?? []), is_string(...)))),
            );
        }

        return $suggestions;
    }
}
