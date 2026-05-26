<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Video;
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
        $video->loadMissing('status', 'transcript');

        $statusKey = $video->status?->key;
        $alreadyConfirmed = (bool) $video->transcript?->is_confirmed_by_user;

        if (
            $alreadyConfirmed ||
            in_array($statusKey, ['waiting_cuts', 'recommending_cuts', 'cutting', 'full_subtitled', 'completed'], true)
        ) {
            return to_route('videos.editor', ['video' => $video->uuid]);
        }

        return view('videos.transcript', ['video' => $video]);
    }

    public function editor(Video $video): View
    {
        return view('videos.editor', ['video' => $video]);
    }

    public function schedule(Video $video): View
    {
        return view('videos.schedule', ['video' => $video]);
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
            ['Content-Type' => $this->contentTypeFor($objectPath)],
        );
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
