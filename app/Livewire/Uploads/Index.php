<?php

declare(strict_types=1);

namespace App\Livewire\Uploads;

use App\Livewire\Concerns\WithToasts;
use App\Models\Video;
use App\Services\HLS\VideoStatusEnum;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Biblioteca dos vídeos longos enviados. Enquanto houver item em preparo a tela
 * faz poll — o empacotamento de um vídeo de horas termina fora da sessão.
 */
final class Index extends Component
{
    use WithPagination;
    use WithToasts;

    private const array BADGE_CLASSES = [
        'awaiting_upload' => 'bg-slate-500/15 text-slate-300',
        'uploaded' => 'bg-sky-500/15 text-sky-300',
        'packaging' => 'bg-amber-500/15 text-amber-300',
        'ready' => 'bg-emerald-500/15 text-emerald-300',
        'failed' => 'bg-red-500/15 text-red-300',
        'rejected' => 'bg-red-500/15 text-red-300',
    ];

    public function delete(string $uuid): void
    {
        $video = Video::query()->where('uuid', $uuid)->where('user_id', auth()->id())->first();

        if (! $video instanceof Video) {
            return;
        }

        // A saída HLS são milhares de objetos sob o prefixo — apagar só a linha
        // deixaria o bucket crescendo para sempre.
        Storage::disk('s3')->delete($video->path());
        Storage::disk('s3')->deleteDirectory($video->hlsPrefix());

        $video->delete();

        $this->toast('Vídeo removido.');
    }

    public function render(): View
    {
        $videos = Video::query()->where('user_id', auth()->id())->latest('id')->paginate(12);

        $rows = $videos->through(fn (Video $video): array => [
            'uuid' => $video->uuid,
            'isPackaging' => $video->status === VideoStatusEnum::Packaging,
            'statusLabel' => $video->status->label(),
            'badgeClass' => self::BADGE_CLASSES[$video->status->value],
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
            'hasPending' => Video::query()->where('user_id', auth()->id())->whereIn('status', VideoStatusEnum::pending())->exists(),
        ]);
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
}
