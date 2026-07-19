<?php

declare(strict_types=1);

namespace App\Livewire\Uploads;

use App\Models\Video;
use App\Services\HLS\VideoStatusEnum;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Player da biblioteca. Componente full-page porque `Route::view()` não passa
 * parâmetro de rota.
 */
#[Layout('layouts.app')]
final class Show extends Component
{
    public Video $video;

    public function mount(Video $video): void
    {
        Gate::authorize('view', $video);

        $this->video = $video;
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
