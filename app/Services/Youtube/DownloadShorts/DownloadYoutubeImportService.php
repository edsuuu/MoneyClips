<?php

declare(strict_types=1);

namespace App\Services\Youtube\DownloadShorts;

use App\Models\YoutubeShort;
use Illuminate\Support\Facades\Date;

final class DownloadYoutubeImportService
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array{imported: int, skipped: int}
     */
    public function importWebhookPayload(array $payload): array
    {
        $channelUrl = (string) ($payload['channel_url'] ?? '');
        $items = $this->items((array) ($payload['items'] ?? []));
        $imported = 0;
        $skipped = 0;

        foreach ($items as $item) {
            $youtubeId = (string) ($item['youtube_id'] ?? '');
            $storagePath = $this->storagePath($item);

            if ($youtubeId === '' || $storagePath === '') {
                $skipped++;

                continue;
            }

            $short = YoutubeShort::query()->firstOrNew(['youtube_id' => $youtubeId]);
            $short->fill([
                'channel_url' => $channelUrl ?: $short->channel_url,
                'title' => (string) ($item['title'] ?? '') ?: $short->title ?: $youtubeId,
                'hashtags' => $this->normalizeHashtags((array) ($item['hashtags'] ?? [])),
                'video_path' => $storagePath,
                'downloaded_at' => $short->downloaded_at ?? Date::now(),
            ]);
            $short->save();

            $imported++;
        }

        return [
            'imported' => $imported,
            'skipped' => $skipped,
        ];
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function storagePath(array $item): string
    {
        $storagePath = (string) ($item['storage_path'] ?? '');
        if ($storagePath !== '') {
            return $storagePath;
        }

        $storage = (array) ($item['storage'] ?? []);

        return (string) ($storage['path'] ?? '');
    }

    /**
     * @param  array<int|string, mixed>  $items
     * @return list<array<string, mixed>>
     */
    private function items(array $items): array
    {
        $normalized = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            /** @var array<string, mixed> $item */
            $normalized[] = $item;
        }

        return $normalized;
    }

    /**
     * @param  array<int|string, mixed>  $hashtags
     * @return array<int, string>
     */
    private function normalizeHashtags(array $hashtags): array
    {
        return array_values(array_filter(
            array_map(
                static fn (mixed $tag): string => is_string($tag) ? mb_trim($tag) : '',
                $hashtags,
            ),
            static fn (string $tag): bool => $tag !== '',
        ));
    }
}
