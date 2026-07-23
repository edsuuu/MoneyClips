<?php

declare(strict_types=1);

namespace App\Livewire\Uploads;

use App\Enums\VideoStatusEnum;
use App\Livewire\Concerns\WithToasts;
use App\Models\File;
use App\Models\Video;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Component;
use Livewire\WithPagination;

final class Index extends Component
{
    use WithPagination;
    use WithToasts;

    /** @return Builder<Video> */
    private function videos(): Builder
    {
        return Video::query()->where('user_id', Auth::id())->with('files');
    }

    public function delete(string $uuid): void
    {
        $video = $this->videos()->where('uuid', $uuid)->first();

        if (! $video instanceof Video) {
            return;
        }

        $video->delete();

        $this->toast('Vídeo removido.');
    }

    private function formatSize(int $bytes): string
    {
        if ($bytes >= 1024 ** 3) {
            return number_format($bytes / 1024 ** 3, 1, ',', '.').' GB';
        }

        return number_format($bytes / 1024 ** 2, 0, ',', '.').' MB';
    }

    private function formatDuration(?int $seconds): string
    {
        if ($seconds === null) {
            return '—';
        }

        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);

        if ($hours > 0) {
            return sprintf('%dh%02dmin', $hours, $minutes);
        }

        return sprintf('%dmin%02ds', $minutes, $seconds % 60);
    }

    public function render(): View
    {
        $rows = $this->videos()->latest('id')->paginate(12)->through(function (Video $video): array {
            $original = $video->file(File::ORIGINAL);

            return [
                'uuid' => $video->uuid,
                'isPackaging' => $video->status === VideoStatusEnum::Packaging,
                'statusLabel' => $video->status->label(),
                'badgeClass' => $video->status->badgeClass(),
                'progress' => $video->progress,
                'isReady' => $video->isReady(),
                'posterUrl' => $video->file(File::POSTER) instanceof File ? route('hls.segment', [$video->uuid, 'poster.jpg']) : null,
                'sizeLabel' => $this->formatSize($original instanceof File ? ($original->size ?? 0) : 0),
                'durationLabel' => $this->formatDuration($video->duration_seconds),
                'resolutionLabel' => $video->height === null ? '—' : $video->height.'p',
                'createdLabel' => $video->created_at?->format('d/m/Y H:i') ?? '—',
                'error' => $video->error,
            ];
        });

        return view('livewire.uploads.index', [
            'videos' => $rows,
            'hasPending' => $this->videos()->whereIn('status', VideoStatusEnum::pending())->exists(),
        ]);
    }
}
