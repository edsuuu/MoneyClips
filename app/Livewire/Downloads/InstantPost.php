<?php

declare(strict_types=1);

namespace App\Livewire\Downloads;

use App\Livewire\Concerns\WithToasts;
use App\Models\SocialAccount;
use App\Models\TiktokPost;
use App\Models\YoutubeShort;
use App\Services\TikTok\TiktokPostService;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Livewire\Component;
use Throwable;

final class InstantPost extends Component
{
    use WithToasts;

    public ?int $selectedShortId = null;

    public bool $postYoutube = true;

    public bool $postTiktok = true;

    public function pickRandom(): void
    {
        $blockedOnTiktok = $this->blockedTiktokYoutubeIds();

        $short = YoutubeShort::query()
            ->whereNotNull('video_path')
            ->where(function ($query) use ($blockedOnTiktok): void {
                $query->whereNull('posted_youtube_at');

                if ($blockedOnTiktok !== []) {
                    $query->orWhereNotIn('youtube_id', $blockedOnTiktok);
                } else {
                    $query->orWhereNotNull('youtube_id');
                }
            })
            ->inRandomOrder()
            ->first();

        if (! $short instanceof YoutubeShort) {
            $this->selectedShortId = null;
            $this->toast('Nenhum vídeo disponível no estoque.', 'danger');

            return;
        }

        $this->selectedShortId = $short->id;
        $this->postYoutube = ! $short->wasPostedToYoutube();
        $this->postTiktok = ! $this->hasActiveTiktokPost($short->youtube_id);

        $this->toast('Vídeo aleatório selecionado: '.($short->title ?? $short->youtube_id));
    }

    public function postSelected(): void
    {
        $short = $this->selectedShort();

        if (! $short instanceof YoutubeShort) {
            $this->toast('Selecione um vídeo primeiro.', 'danger');

            return;
        }

        if (! $this->postYoutube && ! $this->postTiktok) {
            $this->toast('Escolha YouTube, TikTok ou os dois.', 'danger');

            return;
        }

        $messages = [];

        if ($this->postYoutube) {
            $messages[] = $this->queueYoutube($short);
        }

        if ($this->postTiktok) {
            $messages[] = $this->queueTiktok($short);
        }

        $messages = array_values(array_filter($messages));
        if ($messages !== []) {
            $this->toast(implode(' ', $messages));
        }
    }

    public function render(): View
    {
        $short = $this->selectedShort();

        return view('livewire.downloads.instant-post', [
            'short' => $short,
            'counts' => [
                'stock' => YoutubeShort::query()->whereNotNull('video_path')->count(),
                'youtubePosted' => YoutubeShort::query()->whereNotNull('posted_youtube_at')->count(),
                'tiktokQueued' => TiktokPost::query()->whereIn('status', ['queued', 'processing'])->count(),
                'tiktokPosted' => TiktokPost::query()->where('status', 'completed')->count(),
            ],
            'youtubeReady' => SocialAccount::query()
                ->where('platform', 'youtube')
                ->where('is_active', true)
                ->exists(),
            'tiktokStatus' => $short instanceof YoutubeShort
                ? TiktokPost::query()->where('youtube_id', $short->youtube_id)->latest('id')->first()
                : null,
        ]);
    }

    private function selectedShort(): ?YoutubeShort
    {
        if ($this->selectedShortId === null) {
            return null;
        }

        $short = YoutubeShort::query()->find($this->selectedShortId);

        return $short instanceof YoutubeShort ? $short : null;
    }

    private function queueYoutube(YoutubeShort $short): ?string
    {
        if ($short->wasPostedToYoutube()) {
            $this->toast('Este vídeo já foi postado no YouTube.', 'danger');

            return null;
        }

        if ($short->video_path === null || $short->video_path === '') {
            $this->toast('Este vídeo não tem arquivo no storage.', 'danger');

            return null;
        }

        if (! $this->hasYoutubeAccount()) {
            $this->toast('Nenhuma conta do YouTube ativa encontrada.', 'danger');

            return null;
        }

        return 'YouTube enfileirado.';
    }

    private function queueTiktok(YoutubeShort $short): ?string
    {
        if ($this->hasActiveTiktokPost($short->youtube_id)) {
            $this->toast('Este vídeo já está em fila ou postado no TikTok.', 'danger');

            return null;
        }

        try {
            $jobId = resolve(TiktokPostService::class)->queuePost(
                $short->youtube_id,
                $short->title ?? $short->youtube_id,
                array_values(array_filter($short->hashtags ?? [], is_string(...))),
            );
        } catch (Throwable $throwable) {
            report($throwable);
            $this->toast('Não foi possível enviar ao TikTok.', 'danger');

            return null;
        }

        return 'TikTok enfileirado: '.$jobId.'.';
    }

    private function hasYoutubeAccount(): bool
    {
        return SocialAccount::query()
            ->where('platform', 'youtube')
            ->where('is_active', true)
            ->exists();
    }

    private function hasActiveTiktokPost(string $youtubeId): bool
    {
        return TiktokPost::query()
            ->where('youtube_id', $youtubeId)
            ->whereIn('status', TiktokPost::ACTIVE_STATUSES)
            ->exists();
    }

    /**
     * @return list<string>
     */
    private function blockedTiktokYoutubeIds(): array
    {
        /** @var Collection<int, string> $ids */
        $ids = TiktokPost::query()
            ->whereIn('status', TiktokPost::ACTIVE_STATUSES)
            ->whereNotNull('youtube_id')
            ->pluck('youtube_id');

        return array_values($ids->all());
    }
}
