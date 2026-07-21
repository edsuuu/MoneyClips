<?php

declare(strict_types=1);

namespace App\Livewire\Uploads;

use App\Livewire\Concerns\WithToasts;
use App\Models\Video;
use App\Services\HLS\VideoStatusEnum;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Livewire\Component;
use Livewire\WithPagination;

final class Index extends Component
{
    use WithPagination;
    use WithToasts;

    public function delete(string $uuid): void
    {
        $video = $this->videos()->where('uuid', $uuid)->first();

        if (! $video instanceof Video) {
            return;
        }

        Storage::disk('s3')->delete($video->path());
        Storage::disk('s3')->deleteDirectory($video->hlsPrefix());

        $video->delete();

        $this->toast('Vídeo removido.');
    }

    /** @return Builder<Video> */
    private function videos(): Builder
    {
        return Video::query()->where('user_id', Auth::id());
    }

    private function humanSize(int $bytes): string
    {
        if ($bytes >= 1024 ** 3) {
            return number_format($bytes / 1024 ** 3, 1, ',', '.').' GB';
        }

        return number_format($bytes / 1024 ** 2, 0, ',', '.').' MB';
    }

    private function humanDuration(?int $seconds): string
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
        $rows = $this->videos()->latest('id')->paginate(12)->through(fn (Video $video): array => [
            'uuid' => $video->uuid,
            'isPackaging' => $video->status === VideoStatusEnum::Packaging,
            'statusLabel' => $video->status->label(),
            'badgeClass' => $video->status->badgeClass(),
            'progress' => $video->progress,
            'isReady' => $video->isReady(),
            'sizeLabel' => $this->humanSize($video->file_size),
            'durationLabel' => $this->humanDuration($video->duration_seconds),
            'resolutionLabel' => $video->height === null ? '—' : $video->height.'p',
            'createdLabel' => $video->created_at?->format('d/m/Y H:i') ?? '—',
            'error' => $video->error,
        ]);

        return view('livewire.uploads.index', [
            'videos' => $rows,
            'hasPending' => $this->videos()->whereIn('status', VideoStatusEnum::pending())->exists(),
        ]);
    }
}
