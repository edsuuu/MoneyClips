<?php

declare(strict_types=1);

namespace App\Livewire\Uploads;

use App\Enums\TranscriptionStatusEnum;
use App\Enums\VideoStatusEnum;
use App\Livewire\Concerns\WithToasts;
use App\Models\File;
use App\Models\Video;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Livewire\Component;
use Throwable;

use function in_array;
use function route;
use function view;

final class Show extends Component
{
    use WithToasts;

    private const int MAX_SEGMENT_CHARS = 1000;

    public Video $video;

    /** @var list<array{start: float, end: float}> */
    public array $cuts = [];

    /**
     * O dono e filtrado aqui, e nao no resolveRouteBinding do Video: a rota e
     * `Route::view`, que nao dispara model binding.
     */
    public function mount(string $uuid): void
    {
        $this->video = Video::query()
            ->with('files')
            ->where('uuid', $uuid)
            ->where('user_id', auth()->id())
            ->firstOrFail();
    }

    /**
     * Só o texto é editável — start/end/words vêm do S3 (autoritativo) e nunca
     * do cliente. Nada de apagar/adicionar segmento: aplica text por índice.
     *
     * @param  list<array{i?: mixed, text?: mixed}>  $edits
     */
    public function saveTranscript(array $edits): bool
    {
        $disk = Storage::disk('s3');
        $key = $this->video->transcriptPath();

        try {
            if (! $this->video->file(File::TRANSCRIPT) instanceof File || ! $disk->exists($key)) {
                $this->toast('Transcrição não encontrada.', 'danger');

                return false;
            }

            $data = json_decode((string) $disk->get($key), true, 512, JSON_THROW_ON_ERROR);

            if (! is_array($data)) {
                $this->toast('Transcrição inválida.', 'danger');

                return false;
            }

            $segments = is_array($data['segments'] ?? null) ? $data['segments'] : [];
            $count = count($segments);

            if ($count === 0 || count($edits) > $count) {
                $this->toast('Edição inválida.', 'danger');

                return false;
            }

            foreach ($edits as $edit) {
                $index = (int) ($edit['i'] ?? -1);
                $text = mb_trim((string) ($edit['text'] ?? ''));

                if ($index < 0 || $index >= $count || ! is_array($segments[$index])) {
                    $this->toast('Edição inválida.', 'danger');

                    return false;
                }

                if ($text === '' || mb_strlen($text) > self::MAX_SEGMENT_CHARS) {
                    $this->toast('Cada segmento precisa de um texto (não pode ficar vazio).', 'danger');

                    return false;
                }

                $segments[$index]['text'] = $text;
            }

            $data['segments'] = $segments;
            $disk->put($key, json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        } catch (Throwable $throwable) {
            report($throwable);
            $this->toast('Não foi possível salvar a legenda. Tente de novo.', 'danger');

            return false;
        }

        $this->toast('Legenda salva.');

        return true;
    }

    public function addCut(float $start, float $end): void
    {
        $duration = (int) $this->video->duration_seconds;

        if (! is_finite($start) || ! is_finite($end) || $start < 0 || $end <= $start || $end > $duration) {
            $this->toast('Corte inválido.', 'danger');

            return;
        }

        $this->cuts[] = ['start' => $start, 'end' => $end];
    }

    public function removeCut(int $index): void
    {
        $this->cuts = array_values(array_filter(
            $this->cuts,
            fn (int $cutIndex): bool => $cutIndex !== $index,
            ARRAY_FILTER_USE_KEY,
        ));
    }

    public function suggestAiCuts(): never
    {
        dd('Implementar depois');
    }

    private function timecode(int $seconds): string
    {
        $hours = intdiv($seconds, 3600);
        $rest = sprintf('%02d:%02d', intdiv($seconds % 3600, 60), $seconds % 60);

        return $hours > 0 ? $hours.':'.$rest : $rest;
    }

    /** @return list<array{i: int, start: float, text: string}> */
    private function transcriptSegments(Video $video): array
    {
        if ($video->transcription_status !== TranscriptionStatusEnum::Ready) {
            return [];
        }

        $disk = Storage::disk('s3');
        $key = $video->transcriptPath();

        try {
            if (! $disk->exists($key)) {
                return [];
            }

            $data = json_decode((string) $disk->get($key), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable $throwable) {
            report($throwable);

            return [];
        }

        $segments = is_array($data) && is_array($data['segments'] ?? null) ? $data['segments'] : [];

        $out = [];

        foreach (array_values($segments) as $index => $segment) {
            $out[] = [
                'i' => $index,
                'start' => is_array($segment) ? (float) ($segment['start'] ?? 0) : 0.0,
                'text' => is_array($segment) ? (string) ($segment['text'] ?? '') : '',
            ];
        }

        return $out;
    }

    /** @return array<string, float|int|string>|null */
    private function storyboard(Video $video): ?array
    {
        $file = $video->file(File::STORYBOARD);

        if (! $file instanceof File) {
            return null;
        }

        return [
            'url' => route('hls.segment', [$video->uuid, 'storyboard.jpg']),
            'cols' => (int) ($file->meta['cols'] ?? 0),
            'rows' => (int) ($file->meta['rows'] ?? 0),
            'interval' => (float) ($file->meta['interval'] ?? 0),
            'tileWidth' => (int) ($file->meta['tile_width'] ?? 0),
            'tileHeight' => (int) ($file->meta['tile_height'] ?? 0),
        ];
    }

    public function render(): View
    {
        $video = $this->video->fresh(['files']) ?? $this->video;
        $isPackaging = in_array($video->status, [VideoStatusEnum::Uploaded, VideoStatusEnum::Packaging], true);
        $transcription = $video->transcription_status;

        return view('livewire.uploads.show', [
            'dateLabel' => $video->created_at?->format('d/m/Y H:i') ?? '—',
            'isReady' => $video->isReady(),
            'isPackaging' => $isPackaging,
            'isTranscribing' => $transcription === TranscriptionStatusEnum::Processing,
            'transcriptionLabel' => $transcription?->label(),
            'transcriptionBadgeClass' => $transcription?->badgeClass() ?? '',
            'subtitlesUrl' => $transcription === TranscriptionStatusEnum::Ready
                ? route('uploads.subtitles', $video->uuid)
                : null,
            'transcriptSegments' => $this->transcriptSegments($video),
            'captionsKey' => $video->uuid,
            'statusLabel' => $video->status->label(),
            'progress' => $video->progress,
            'error' => $video->error,
            'hlsUrl' => $video->isReady() ? route('hls.master', $video->uuid) : null,
            'fallbackUrl' => $video->presignedUrl(),
            'posterUrl' => $video->file(File::POSTER) === null ? null : route('hls.segment', [$video->uuid, 'poster.jpg']),
            'renditions' => $video->renditions(),
            'storyboard' => $this->storyboard($video),
            'durationSeconds' => (int) $video->duration_seconds,
            'cutItems' => array_map(fn (array $cut): array => [
                'start' => $cut['start'],
                'end' => $cut['end'],
                'rangeLabel' => $this->timecode((int) $cut['start']).' – '.$this->timecode((int) $cut['end']),
                'durationLabel' => $this->timecode(max(1, (int) $cut['end'] - (int) $cut['start'])),
            ], $this->cuts),
        ]);
    }
}
