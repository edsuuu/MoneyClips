<?php

declare(strict_types=1);

namespace App\Livewire\Shorts;

use App\Livewire\Concerns\WithToasts;
use App\Models\SocialAccount;
use App\Models\YoutubeShort;
use App\Services\TikTok\TikTokPostDispatcher;
use Illuminate\View\View;
use Livewire\Component;
use Livewire\WithPagination;
use Throwable;

/**
 * Painel do estoque de Shorts (pipeline de auto-postagem): lista os Shorts
 * baixados, o que já foi postado e permite disparar uma postagem na hora.
 *
 * O download continua sendo feito pelo CLI (youtube:download-shorts) e o
 * sorteio automático pelo scheduler (youtube:dispatch-posts).
 */
final class Index extends Component
{
    use WithPagination;
    use WithToasts;

    /** Filtro: all | available | posted */
    public string $filter = 'all';

    public function setFilter(string $filter): void
    {
        if (! in_array($filter, ['all', 'available', 'posted'], true)) {
            return;
        }

        $this->filter = $filter;
        $this->resetPage();
    }

    /** Enfileira a postagem imediata de um Short específico. */
    public function postNow(int $shortId): void
    {
        $short = YoutubeShort::query()->find($shortId);

        if ($short === null) {
            $this->toast('Short não encontrado.', 'danger');

            return;
        }

        if ($short->wasPostedToYoutube()) {
            $this->toast('Este Short já foi postado.', 'danger');

            return;
        }

        if ($short->video_path === null) {
            $this->toast('Este Short ainda não foi baixado.', 'danger');

            return;
        }

        if (! $this->hasYoutubeAccount()) {
            $this->toast('Conecte uma conta do YouTube em "Contas vinculadas" antes de postar.', 'danger');

            return;
        }

        $this->toast('Postagem enfileirada. Acompanhe o resultado em instantes.');
    }

    /** Sorteia um Short e enfileira a postagem no TikTok (igual ao scheduler). */
    public function dispatchTiktok(TikTokPostDispatcher $dispatcher): void
    {
        try {
            $result = $dispatcher->dispatchOne();
        } catch (Throwable $throwable) {
            report($throwable);
            $this->toast('Não foi possível enfileirar: '.$throwable->getMessage(), 'danger');

            return;
        }

        $label = $result['title'] ?? 'sorteio dentro do uploader';
        $this->toast(sprintf('Post no TikTok enfileirado (%s): %s', $result['source'], $label));
    }

    /** Sorteia um Short do estoque e enfileira a postagem (igual ao scheduler). */
    public function dispatchRandom(): void
    {
        $short = YoutubeShort::query()->availableToPost()->inRandomOrder()->first();

        if ($short === null) {
            $this->toast('Nenhum Short disponível para postar.', 'danger');

            return;
        }

        if (! $this->hasYoutubeAccount()) {
            $this->toast('Conecte uma conta do YouTube em "Contas vinculadas" antes de postar.', 'danger');

            return;
        }

        $this->toast('Short sorteado e enfileirado: '.($short->title ?? $short->youtube_id));
    }

    public function render(): View
    {
        $downloaded = YoutubeShort::query()->whereNotNull('video_path')->count();
        $available = YoutubeShort::query()->availableToPost()->count();
        $posted = YoutubeShort::query()->whereNotNull('posted_youtube_at')->count();

        $shorts = YoutubeShort::query()
            ->when($this->filter === 'available', fn ($q) => $q->availableToPost())
            ->when($this->filter === 'posted', fn ($q) => $q->whereNotNull('posted_youtube_at'))
            ->latest('id')
            ->paginate(15);

        $account = SocialAccount::query()
            ->where('platform', 'youtube')
            ->where('is_active', true)
            ->latest('id')
            ->first();

        return view('livewire.shorts.index', [
            'shorts' => $shorts,
            'counts' => [
                'total' => YoutubeShort::query()->count(),
                'downloaded' => $downloaded,
                'available' => $available,
                'posted' => $posted,
            ],
            'account' => $account,
        ]);
    }

    private function hasYoutubeAccount(): bool
    {
        return SocialAccount::query()
            ->where('platform', 'youtube')
            ->where('is_active', true)
            ->exists();
    }
}
