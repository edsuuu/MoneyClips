<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Video;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class HLSStreamController extends Controller
{
    private const string SEGMENT_PATTERN = '#^(poster\.jpg|storyboard\.jpg|[A-Za-z0-9_-]+/(init(_\d+)?\.mp4|seg_\d{1,6}\.m4s|index\.m3u8))$#';

    private const int ACCEL_TTL_MINUTES = 5;

    public function master(Video $video): Response|StreamedResponse
    {
        abort_unless($video->isReady(), 404);

        return $this->deliver($video->masterPlaylistPath(), 'application/vnd.apple.mpegurl', false);
    }

    public function segment(Video $video, string $path): Response|StreamedResponse
    {
        abort_unless($video->isReady(), 404);
        abort_unless(preg_match(self::SEGMENT_PATTERN, $path) === 1, 404);

        $isPlaylist = str_ends_with($path, '.m3u8');

        $key = match ($path) {
            'poster.jpg' => $video->posterPath(),
            'storyboard.jpg' => $video->storyboardPath(),
            default => $video->hlsPrefix().'/'.$path,
        };

        return $this->deliver(
            $key,
            $isPlaylist ? 'application/vnd.apple.mpegurl' : $this->segmentMime($path),
            ! $isPlaylist,
        );
    }

    private function deliver(string $key, string $contentType, bool $immutable): Response|StreamedResponse
    {
        $disk = Storage::disk('s3');
        abort_unless($disk->exists($key), 404);

        $cacheControl = $immutable
            ? 'private, max-age=31536000, immutable'
            : 'private, no-cache';

        if (config('services.hls.delivery') === 'accel') {
            $target = $disk->temporaryUrl($key, now()->addMinutes(self::ACCEL_TTL_MINUTES));

            return response('', 200, [
                'X-Accel-Redirect' => '/_minio_signed/'.mb_ltrim(parse_url((string) $target, PHP_URL_PATH).'?'.parse_url((string) $target, PHP_URL_QUERY), '/'),
                'Content-Type' => $contentType,
                'Cache-Control' => $cacheControl,
            ]);
        }

        return response()->stream(function () use ($disk, $key): void {
            $stream = $disk->readStream($key);
            if (is_resource($stream)) {
                fpassthru($stream);
                fclose($stream);
            }
        }, 200, [
            'Content-Type' => $contentType,
            'Cache-Control' => $cacheControl,
            'Accept-Ranges' => 'bytes',
        ]);
    }

    private function segmentMime(string $path): string
    {
        return match (true) {
            str_ends_with($path, '.jpg') => 'image/jpeg',
            str_ends_with($path, '.mp4') => 'video/mp4',
            default => 'video/iso.segment',
        };
    }
}
