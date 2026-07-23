<?php

declare(strict_types=1);

namespace App\Livewire\Uploads;

use App\Enums\VideoStatusEnum;
use App\Models\File;
use App\Models\Video;
use Illuminate\View\View;
use Livewire\Component;

use function in_array;
use function route;
use function view;

final class Show extends Component
{
    public Video $video;

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
     * ponytail: cortes mock — placeholder da segmentação automática que ainda
     * não existe. Vira consulta real quando o pipeline de cortes chegar.
     *
     * @return list<array{title: string, start: int, rangeLabel: string, durationLabel: string}>
     */
    private function mockClips(): array
    {
        $ranges = [
            [20, 100, 'Abertura sem enrolação'],
            [140, 210, 'logsgsjsjdhd'],
            [275, 360, 'O momento que viralizou'],
            [430, 495, 'Reação inesperada'],
            [612, 700, 'Melhor tirada do vídeo'],
            [880, 965, 'Fechamento com chave de ouro'],
        ];

        return array_map(fn (array $range): array => [
            'title' => $range[2],
            'start' => $range[0],
            'rangeLabel' => $this->timecode($range[0]).' – '.$this->timecode($range[1]),
            'durationLabel' => $this->timecode($range[1] - $range[0]),
        ], $ranges);
    }

    private function timecode(int $seconds): string
    {
        return sprintf('%02d:%02d', intdiv($seconds, 60), $seconds % 60);
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

        return view('livewire.uploads.show', [
            'title' => 'Vídeo enviado em '.($video->created_at?->format('d/m/Y H:i') ?? '—'),
            'dateLabel' => $video->created_at?->format('d/m/Y H:i') ?? '—',
            'isReady' => $video->isReady(),
            'isPackaging' => $isPackaging,
            'statusLabel' => $video->status->label(),
            'progress' => $video->progress,
            'error' => $video->error,
            'hlsUrl' => $video->isReady() ? route('hls.master', $video->uuid) : null,
            'fallbackUrl' => $video->presignedUrl(),
            'posterUrl' => $video->file(File::POSTER) === null ? null : route('hls.segment', [$video->uuid, 'poster.jpg']),
            'renditions' => $video->renditions(),
            'storyboard' => $this->storyboard($video),
            'clips' => $video->isReady() ? $this->mockClips() : [],
        ]);
    }
}
