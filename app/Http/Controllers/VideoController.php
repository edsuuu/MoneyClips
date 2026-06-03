<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\File;
use App\Models\ScheduledPost;
use App\Models\Video;
use App\Services\SocialPublishing\SocialPublisherRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class VideoController extends Controller
{
    public function transcript(Video $video): RedirectResponse
    {
        return to_route('videos.editor', ['video' => $video->uuid]);
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

    public function stream(Request $request, Video $video, string $path): StreamedResponse
    {
        $objectPath = 'videos/'.$video->uuid.'/hls/'.mb_ltrim($path, '/');

        return $this->streamObject($request, $objectPath);
    }

    /**
     * Serve um corte renderizado pelo próprio domínio HTTPS do Laravel.
     *
     * Evita o presigned URL HTTP do MinIO (que o browser bloqueia como
     * mixed-content numa página HTTPS) e habilita streaming progressivo via
     * HTTP Range — o vídeo começa a tocar sem baixar o arquivo inteiro.
     */
    public function cut(Request $request, Video $video, string $type): StreamedResponse
    {
        $cut = $video->cuts()->where('type', $type)->firstOrFail();
        $rendered = $cut->files()->where('type', $type)->latest()->first();
        abort_unless($rendered instanceof File, 404);

        return $this->streamObject($request, $rendered->path);
    }

    /**
     * Faz proxy de um objeto do MinIO com suporte a HTTP Range (206).
     *
     * MinIO e Laravel rodam no mesmo servidor, então a leitura é local/rápida;
     * o gargalo é só servidor → browser. Servir com Range deixa o player pedir
     * apenas o trecho que precisa, em vez de baixar o segmento inteiro.
     */
    private function streamObject(Request $request, string $objectPath): StreamedResponse
    {
        $disk = Storage::disk('minio');
        abort_unless($disk->exists($objectPath), 404);

        $size = (int) $disk->size($objectPath);
        $contentType = $this->contentTypeFor($objectPath);
        $cacheControl = $this->cacheControlFor($objectPath);

        $start = 0;
        $end = $size - 1;
        $status = 200;
        $headers = [
            'Content-Type' => $contentType,
            'Cache-Control' => $cacheControl,
            'Accept-Ranges' => 'bytes',
        ];

        $range = $request->headers->get('Range');
        if (is_string($range) && preg_match('/bytes=(\d*)-(\d*)/', $range, $m) === 1) {
            if ($m[1] !== '') {
                $start = (int) $m[1];
            }

            if ($m[2] !== '') {
                $end = (int) $m[2];
            }

            if ($start > $end || $start >= $size) {
                return new StreamedResponse(null, 416, [
                    'Content-Range' => 'bytes */'.$size,
                    'Accept-Ranges' => 'bytes',
                ]);
            }

            $end = min($end, $size - 1);
            $status = 206;
            $headers['Content-Range'] = sprintf('bytes %d-%d/%d', $start, $end, $size);
        }

        $length = $end - $start + 1;
        $headers['Content-Length'] = (string) $length;

        return new StreamedResponse(
            function () use ($disk, $objectPath, $start, $length): void {
                $stream = $disk->readStream($objectPath);
                if ($stream === null) {
                    return;
                }

                if ($start > 0) {
                    fseek($stream, $start);
                }

                $remaining = $length;
                $chunkSize = 1024 * 1024; // 1 MB por iteração
                while ($remaining > 0 && ! feof($stream)) {
                    $read = min($chunkSize, $remaining);
                    $buffer = fread($stream, $read);
                    if ($buffer === false) {
                        break;
                    }

                    echo $buffer;
                    flush();
                    $remaining -= mb_strlen($buffer, '8bit');
                }

                fclose($stream);
            },
            $status,
            $headers,
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
