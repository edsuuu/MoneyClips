<?php

declare(strict_types=1);

namespace App\Livewire\Videos;

use App\Jobs\ProcessVideoJob;
use App\Livewire\Concerns\WithToasts;
use App\Models\Status;
use App\Models\Video;
use Illuminate\View\View;
use Livewire\Component;

final class Create extends Component
{
    use WithToasts;

    public string $url = '';

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'url' => ['required', 'url'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'url.required' => 'Informe a URL do vídeo.',
            'url.url' => 'A URL informada não é válida.',
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'url' => 'URL do vídeo',
        ];
    }

    public function start(): void
    {
        /** @var array{url: string} $validated */
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

        $this->toast('Vídeo adicionado. O processamento começa em instantes.');

        $this->redirectRoute('videos.editor', ['uuid' => $video->uuid], navigate: true);
    }

    public function render(): View
    {
        return view('livewire.videos.create');
    }
}
