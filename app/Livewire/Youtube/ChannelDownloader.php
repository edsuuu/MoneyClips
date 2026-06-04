<?php

declare(strict_types=1);

namespace App\Livewire\Youtube;

use App\Jobs\YoutubeDownloadJob;
use App\Models\YoutubeShort;
use App\Services\Youtube\YoutubeChannelService;
use Flux\Flux;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\View\View;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Tela de download de Shorts de um canal do YouTube.
 *
 * Chama o YoutubeChannelService diretamente para listar os Shorts e dispara
 * o YoutubeDownloadJob para baixar. Funcionalidade isolada.
 */
final class ChannelDownloader extends Component
{
    use WithPagination;

    #[Validate('required|url')]
    public string $channelUrl = '';

    /** @var array<int, array{id: string, title: string, description: string, hashtags: array<int, string>, url: string}>|null */
    public ?array $videos = null;

    public bool $downloadAll = true;

    public ?int $limit = null;

    /** Busca os Shorts do canal usando o service. */
    public function fetchChannel(YoutubeChannelService $service): void
    {
        $this->validate();

        $this->videos = $service->listShorts($this->channelUrl);
        $this->limit = count($this->videos);

        if ($this->videos === []) {
            Flux::toast('Nenhum Short encontrado para este canal.', variant: 'warning');
        }
    }

    /** Enfileira o download dos vídeos selecionados. */
    public function startDownload(): void
    {
        if (empty($this->videos)) {
            return;
        }

        $videos = $this->videos;

        if (! $this->downloadAll && $this->limit !== null && $this->limit > 0) {
            $videos = array_slice($videos, 0, $this->limit);
        }

        $selected = array_map(
            static fn (array $v): array => ['id' => $v['id'], 'url' => $v['url']],
            $videos,
        );

        YoutubeDownloadJob::dispatch($selected);

        Flux::toast(count($selected).' vídeo(s) enviados para download. As postagens serão agendadas automaticamente.');
    }

    public function render(): View
    {
        $downloaded = YoutubeShort::query()
            ->withCount('jobs')
            ->with(['jobs' => fn (Relation $q) => $q->latest('scheduled_at')])
            ->latest('downloaded_at')
            ->paginate(10);

        return view('livewire.youtube.channel-downloader', [
            'downloaded' => $downloaded,
        ]);
    }
}
