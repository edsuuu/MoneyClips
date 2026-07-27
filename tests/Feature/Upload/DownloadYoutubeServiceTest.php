<?php

declare(strict_types=1);

use App\Models\Video;
use App\Services\Upload\DownloadYoutubeService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it('maps the metadata response into the DTO', function (): void {
    Http::fake(['*/videos/metadata*' => Http::response([
        'youtube_id' => 'abc123',
        'title' => 'Meu vídeo',
        'duration_seconds' => 125,
        'width' => 1920,
        'height' => 1080,
        'channel' => 'Canal',
        'thumbnail' => 'https://i.ytimg.com/vi/abc123/maxresdefault.jpg',
    ])]);

    $metadata = resolve(DownloadYoutubeService::class)->fetchMetadata('https://youtu.be/abc123');

    expect($metadata?->youtubeId)->toBe('abc123')
        ->and($metadata?->title)->toBe('Meu vídeo')
        ->and($metadata?->durationSeconds)->toBe(125)
        ->and($metadata?->height)->toBe(1080)
        ->and($metadata?->thumbnailUrl)->toBe('https://i.ytimg.com/vi/abc123/maxresdefault.jpg');
});

it('returns null when the video does not exist', function (): void {
    Http::fake(['*/videos/metadata*' => Http::response(['detail' => 'video unavailable'], 404)]);

    expect(resolve(DownloadYoutubeService::class)->fetchMetadata('https://youtu.be/sumiu'))->toBeNull();
});

it('throws when the service is broken', function (): void {
    Http::fake(['*/videos/metadata*' => Http::response('boom', 500)]);

    resolve(DownloadYoutubeService::class)->fetchMetadata('https://youtu.be/abc123');
})->throws(RuntimeException::class);

it('starts the download with the uuid and the exact destination key', function (): void {
    Http::fake(['*/videos/download' => Http::response(['video_uuid' => 'x', 'status' => 'queued'], 202)]);

    $video = Video::factory()->downloading()->create();

    resolve(DownloadYoutubeService::class)->startDownload($video, 'https://youtu.be/abc123');

    Http::assertSent(fn (Request $request): bool => $request['video_uuid'] === $video->uuid
        && $request['video_key'] === $video->originalPath()
        && $request['url'] === 'https://youtu.be/abc123'
        && is_string($request['webhook_url'])
        && str_contains($request['webhook_url'], '/api/webhook/download-video'));
});
