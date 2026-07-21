<?php

declare(strict_types=1);

namespace App\Livewire\Uploads;

use App\Models\Video;
use App\Services\HLS\VideoStatusEnum;
use Illuminate\View\View;
use Livewire\Component;

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
            ->where('uuid', $uuid)
            ->where('user_id', auth()->id())
            ->firstOrFail();
    }

    public function render(): View
    {
        $video = $this->video->fresh() ?? $this->video;
        $isPackaging = in_array($video->status, [VideoStatusEnum::Uploaded, VideoStatusEnum::Packaging], true);

        return view('livewire.uploads.show', [
            'title' => 'Vídeo enviado em '.($video->created_at?->format('d/m/Y H:i') ?? '—'),
            'isReady' => $video->isReady(),
            'isPackaging' => $isPackaging,
            'statusLabel' => $video->status->label(),
            'progress' => $video->progress,
            'error' => $video->error,
            'hlsUrl' => $video->isReady() ? route('hls.master', $video->uuid) : null,
            'fallbackUrl' => $video->presignedUrl(),
            'posterUrl' => $video->poster_path === null ? null : route('hls.segment', [$video->uuid, 'poster.jpg']),
            'renditions' => $video->renditions ?? [],
        ]);
    }
}
