<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\File;
use App\Models\Video;
use App\Models\ScheduledPost;
use App\Services\SocialPublishing\SocialPublisherRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class VideoController extends Controller
{
    public function index(): View
    {
        return view('videos.index');
    }

    public function create(): View
    {
        return view('videos.create');
    }

    public function transcript(Video $video): View|RedirectResponse
    {
        return to_route('videos.editor', ['video' => $video->uuid]);
    }

    public function editor(Video $video): View
    {
        return view('videos.editor', ['video' => $video]);
    }

    public function schedule(Video $video): View
    {
        return view('videos.schedule', ['video' => $video]);
    }

    public function publications(Video $video, SocialPublisherRegistry $registry): View
    {
        $posts = ScheduledPost::query()
            ->where('video_id', $video->id)
            ->with(['account', 'cut'])
            ->latest()
            ->paginate(20);

        return view('videos.publications', [
            'video' => $video,
            'posts' => $posts,
            'platformLabels' => $registry->labels(),
        ]);
    }

    public function thumbnail(Video $video): StreamedResponse
    {
        $thumbnail = $video->files()->where('type', 'thumbnail')->latest()->first();
        abort_unless($thumbnail instanceof File, 404);

        $disk = Storage::disk($thumbnail->disk ?: 'minio');
        abort_unless($disk->exists($thumbnail->path), 404);

        $stream = $disk->readStream($thumbnail->path);
        abort_if($stream === null, 404);

        return new StreamedResponse(
            static function () use ($stream): void {
                fpassthru($stream);
                fclose($stream);
            },
            200,
            [
                'Content-Type' => 'image/jpeg',
                'Cache-Control' => 'private, max-age=86400, stale-while-revalidate=604800',
            ],
        );
    }

    public function stream(Video $video, string $path): StreamedResponse
    {
        $objectPath = 'videos/'.$video->uuid.'/hls/'.mb_ltrim($path, '/');
        $disk = Storage::disk('minio');

        abort_unless($disk->exists($objectPath), 404);

        $stream = $disk->readStream($objectPath);
        abort_if($stream === null, 404);

        return new StreamedResponse(
            static function () use ($stream): void {
                fpassthru($stream);
                fclose($stream);
            },
            200,
            [
                'Content-Type' => $this->contentTypeFor($objectPath),
                'Cache-Control' => $this->cacheControlFor($objectPath),
            ],
        );
    }

    private function cacheControlFor(string $objectPath): string
    {
        return str_ends_with(mb_strtolower($objectPath), '.m3u8')
            ? 'private, no-cache'
            : 'private, max-age=3600, immutable';
    }

    private function contentTypeFor(string $objectPath): string
    {
        $extension = mb_strtolower(pathinfo($objectPath, PATHINFO_EXTENSION));

        return match ($extension) {
            'm3u8' => 'application/vnd.apple.mpegurl',
            'ts' => 'video/mp2t',
            'm4s' => 'video/iso.segment',
            default => 'application/octet-stream',
        };
    }
}
