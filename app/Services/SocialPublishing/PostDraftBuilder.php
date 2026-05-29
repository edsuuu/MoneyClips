<?php

declare(strict_types=1);

namespace App\Services\SocialPublishing;

use App\Models\Cut;
use App\Models\Video;
use App\Support\Cast;
use Illuminate\Support\Str;

/**
 * Gera um rascunho consistente de título, descrição e hashtags por corte.
 * O objetivo é deixar o caminho de publicação/agendamento preenchido por padrão.
 */
final class PostDraftBuilder
{
    /**
     * @return array{part_label: string, title: string, description: string, hashtags: list<string>}
     */
    public function forCut(Video $video, Cut $cut): array
    {
        $partLabel = $this->partLabel($cut);
        $title = $this->normalizeTitle((string) ($cut->title ?? ''), $video, $partLabel);
        $description = $this->normalizeDescription((string) ($cut->description ?? ''), $video, $partLabel);
        $hashtags = $this->normalizeHashtags($cut, $partLabel);

        return [
            'part_label' => $partLabel,
            'title' => $title,
            'description' => $description,
            'hashtags' => $hashtags,
        ];
    }

    public function partLabel(Cut $cut): string
    {
        $label = mb_trim((string) ($cut->name ?: 'PT'.$cut->index));

        return mb_strtoupper($label);
    }

    /**
     * @return list<string>
     */
    private function normalizeHashtags(Cut $cut, string $partLabel): array
    {
        $existing = array_values(array_filter(array_map(
            static fn ($tag): string => mb_strtolower(mb_ltrim(mb_trim(Cast::str($tag)), '#')),
            is_array($cut->hashtags) ? $cut->hashtags : [],
        ), static fn (string $tag): bool => $tag !== ''));

        $defaults = [
            'shorts',
            'cortes',
            mb_strtolower($partLabel),
        ];

        return array_values(array_unique([...$existing, ...$defaults]));
    }

    private function normalizeTitle(string $current, Video $video, string $partLabel): string
    {
        $title = mb_trim($current);
        if ($title === '') {
            $baseTitle = mb_trim((string) ($video->title ?? 'Corte pronto para publicar'));
            $title = $baseTitle !== '' ? $baseTitle : 'Corte pronto para publicar';
        }

        if (mb_stripos($title, $partLabel) === false) {
            $title = mb_trim($title.' | '.$partLabel);
        }

        return Str::limit($title, 100, '');
    }

    private function normalizeDescription(string $current, Video $video, string $partLabel): string
    {
        $description = mb_trim($current);
        $videoTitle = mb_trim((string) ($video->title ?? 'este video'));
        $fallback = sprintf('%s do video %s.', $partLabel, $videoTitle !== '' ? $videoTitle : 'selecionado');

        if ($description === '') {
            return $fallback;
        }

        return mb_stripos($description, $partLabel) === false
            ? mb_trim($description."\n\n".$fallback)
            : $description;
    }
}
