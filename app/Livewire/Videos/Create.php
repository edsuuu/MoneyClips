<?php

declare(strict_types=1);

namespace App\Livewire\Videos;

use App\Jobs\ProcessVideoJob;
use App\Models\Status;
use App\Models\Video;
use Flux\Flux;
use Illuminate\View\View;
use Livewire\Component;

final class Create extends Component
{
    public string $url = '';

    public function rules(): array
    {
        return [
            'url' => ['required', 'url'],
        ];
    }

    public function messages(): array
    {
        return [
            'url.required' => 'Informe a URL do vídeo.',
            'url.url' => 'A URL informada não é válida.',
        ];
    }

    public function attributes(): array
    {
        return [
            'url' => 'URL do vídeo',
        ];
    }

    public function start(): void
    {
        $validated = $this->validate();

        $existingVideo = Video::query()->where('url', $validated['url'])
            ->first();

        if ($existingVideo) {
            // nao faz nada por enquanto
        }

        $video = Video::query()->create([
            'url' => $validated['url'],
            'status_id' => Status::idFor('queued'),
        ]);

        dispatch(new ProcessVideoJob($video));

        Flux::toast('Vídeo adicionado. O processamento começa em instantes.');

        $this->redirectRoute('videos.editor', ['uuid' => $video->uuid], navigate: true);
    }

    public function render(): View
    {
        return view('livewire.videos.create');
    }
}
