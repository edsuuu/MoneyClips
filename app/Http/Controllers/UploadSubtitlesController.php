<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\File;
use App\Models\Video;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;

/**
 * Serve a transcrição como WebVTT pro `<track>` nativo do player: o browser
 * sincroniza as legendas pelo currentTime sozinho. Gerado on-the-fly do
 * transcript.json (fonte única), então reflete qualquer reprocessamento.
 */
final class UploadSubtitlesController extends Controller
{
    public function __invoke(Video $video): Response
    {
        abort_unless($video->file(File::TRANSCRIPT) instanceof File, 404);

        $disk = Storage::disk('s3');
        $key = $video->transcriptPath();
        abort_unless($disk->exists($key), 404);

        /** @var array{segments?: list<array{start?: float|int, end?: float|int, text?: string}>} $data */
        $data = json_decode((string) $disk->get($key), true) ?: [];

        return response($this->toVtt($data['segments'] ?? []), 200, [
            'Content-Type' => 'text/vtt; charset=UTF-8',
            'Cache-Control' => 'private, no-cache',
        ]);
    }

    /** @param list<array{start?: float|int, end?: float|int, text?: string}> $segments */
    private function toVtt(array $segments): string
    {
        $lines = ['WEBVTT', ''];

        foreach ($segments as $segment) {
            $start = (float) ($segment['start'] ?? 0);
            $end = (float) ($segment['end'] ?? 0);
            $text = mb_trim((string) ($segment['text'] ?? ''));
            if ($text === '') {
                continue;
            }

            if ($end <= $start) {
                continue;
            }

            $lines[] = $this->timestamp($start).' --> '.$this->timestamp($end);
            $lines[] = $text;
            $lines[] = '';
        }

        return implode("\n", $lines);
    }

    private function timestamp(float $seconds): string
    {
        $ms = (int) round($seconds * 1000);

        return sprintf(
            '%02d:%02d:%02d.%03d',
            intdiv($ms, 3_600_000),
            intdiv($ms % 3_600_000, 60_000),
            intdiv($ms % 60_000, 1000),
            $ms % 1000,
        );
    }
}
