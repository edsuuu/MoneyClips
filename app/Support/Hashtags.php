<?php

declare(strict_types=1);

namespace App\Support;

final class Hashtags
{
    /**
     * "shorts, #podcast corte" → ['#shorts', '#podcast', '#corte'].
     *
     * @return list<string>
     */
    public static function parse(string $raw): array
    {
        $tags = [];
        foreach (preg_split('/[\s,]+/', $raw) ?: [] as $tag) {
            $tag = mb_trim($tag);
            if ($tag === '') {
                continue;
            }

            $tags[] = str_starts_with($tag, '#') ? $tag : '#'.$tag;
        }

        return array_values(array_unique($tags));
    }

    /**
     * ['#shorts', '#podcast'] → "#shorts #podcast" (valor do input).
     *
     * @param  array<int, string>|null  $tags
     */
    public static function toInput(?array $tags): string
    {
        return implode(' ', $tags ?? []);
    }
}
