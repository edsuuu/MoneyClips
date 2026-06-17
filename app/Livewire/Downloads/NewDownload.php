<?php

declare(strict_types=1);

namespace App\Livewire\Downloads;

use App\Livewire\Concerns\WithToasts;
use App\Services\Youtube\DownloadShortsClient;
use Illuminate\View\View;
use Livewire\Component;
use Throwable;

final class NewDownload extends Component
{
    use WithToasts;

    public string $channelUrl = '';

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'channelUrl' => ['required', 'url'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'channelUrl.required' => 'Informe a URL do canal.',
            'channelUrl.url' => 'A URL informada não é válida.',
        ];
    }

    public function start(): void
    {
        /** @var array{channelUrl: string} $validated */
        $validated = $this->validate();

        try {
            $count = resolve(DownloadShortsClient::class)->createDownload($validated['channelUrl']);
        } catch (Throwable $throwable) {
            report($throwable);
            $this->toast('Não foi possível disparar o download no microserviço.', 'danger');

            return;
        }

        $this->channelUrl = '';

        if ($count === 0) {
            $this->toast('Nenhum Short encontrado nesse canal.', 'warning');

            return;
        }

        $this->toast(sprintf(
            'Disparados %d downloads. Os vídeos vão aparecer na lista conforme o microserviço terminar.',
            $count,
        ));
    }

    public function render(): View
    {
        return view('livewire.downloads.new-download');
    }
}
