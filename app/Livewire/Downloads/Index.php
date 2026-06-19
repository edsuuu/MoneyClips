<?php

declare(strict_types=1);

namespace App\Livewire\Downloads;

use App\Livewire\Concerns\WithToasts;
use App\Models\TiktokPost;
use App\Models\YoutubeShort;
use App\Services\TikTok\TiktokPostService;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Livewire\Component;
use Livewire\WithPagination;
use Throwable;

final class Index extends Component
{
    use WithPagination;
    use WithToasts;

    private const int PER_PAGE = 15;

    public bool $showTiktokConfirmation = false;

    public string $pendingYoutubeId = '';

    public string $pendingTitle = '';

    /** @var array<int, string> */
    public array $pendingHashtags = [];

    public function requestPostToTiktok(string $youtubeId): void
    {
        $youtubeId = mb_trim($youtubeId);
        if ($youtubeId === '') {
            $this->toast('Vídeo inválido.', 'danger');

            return;
        }

        $short = YoutubeShort::query()
            ->where('youtube_id', $youtubeId)
            ->first();

        $this->pendingYoutubeId = $youtubeId;
        $this->pendingTitle = $short instanceof YoutubeShort && $short->title !== null && mb_trim($short->title) !== ''
            ? mb_trim($short->title)
            : $youtubeId;
        $this->pendingHashtags = $short instanceof YoutubeShort
            ? $this->normalizeHashtags((array) ($short->hashtags ?? []))
            : [];
        $this->showTiktokConfirmation = true;
    }

    public function cancelPostToTiktok(): void
    {
        $this->clearPendingTiktokPost();
    }

    public function postPendingToTiktok(): void
    {
        $youtubeId = $this->pendingYoutubeId;
        $title = $this->pendingTitle;
        $hashtags = $this->pendingHashtags;

        $this->clearPendingTiktokPost();
        $this->postToTiktok($youtubeId, $title, $hashtags);
    }

    /**
     * @param  array<int, string>  $hashtags
     */
    public function postToTiktok(string $youtubeId, string $title = '', array $hashtags = []): void
    {
        $youtubeId = mb_trim($youtubeId);
        if ($youtubeId === '') {
            $this->toast('Vídeo inválido.', 'danger');

            return;
        }

        $existing = TiktokPost::query()
            ->where('youtube_id', $youtubeId)
            ->whereIn('status', TiktokPost::ACTIVE_STATUSES)
            ->latest('id')
            ->first();

        if ($existing instanceof TiktokPost) {
            $this->toast('Este vídeo já está em fila ou já foi postado no TikTok.', 'danger');

            return;
        }

        try {
            $jobId = resolve(TiktokPostService::class)->queuePost(
                $youtubeId,
                $title,
                $this->normalizeHashtags($hashtags),
            );
        } catch (Throwable $throwable) {
            report($throwable);
            $this->toast('Não foi possível enviar o post ao microserviço TikTok.', 'danger');

            return;
        }

        $this->toast(sprintf('Post enviado para a fila do TikTok. Job %s.', $jobId));
    }

    public function render(): View
    {
        $page = max(1, (int) ($this->getPage()));
        $shorts = YoutubeShort::query()
            ->whereNotNull('video_path')
            ->latest('id')
            ->paginate(self::PER_PAGE, ['*'], 'page', $page);

        $items = new LengthAwarePaginator(
            $this->decorateItems($shorts->getCollection()),
            $shorts->total(),
            self::PER_PAGE,
            $shorts->currentPage(),
            ['path' => request()->url()],
        );

        return view('livewire.downloads.index', [
            'items' => $items,
            'counts' => [
                'downloaded' => YoutubeShort::query()->whereNotNull('video_path')->count(),
                'queued' => TiktokPost::query()->whereIn('status', ['queued', 'processing'])->count(),
                'posted' => TiktokPost::query()->where('status', 'completed')->count(),
                'failed' => TiktokPost::query()->where('status', 'failed')->count(),
            ],
        ]);
    }

    /**
     * @param  Collection<int, YoutubeShort>  $items
     * @return list<array<string, mixed>>
     */
    private function decorateItems(Collection $items): array
    {
        $youtubeIds = $items->pluck('youtube_id')->filter()->values();

        /** @var Collection<string, TiktokPost> $posts */
        $posts = TiktokPost::query()
            ->whereIn('youtube_id', $youtubeIds)
            ->latest('id')
            ->get()
            ->unique('youtube_id')
            ->keyBy('youtube_id');

        return array_values($items
            ->map(function (YoutubeShort $item) use ($posts): array {
                $youtubeId = $item->youtube_id;
                $post = $posts->get($youtubeId);

                return [
                    'youtube_id' => $youtubeId,
                    'title' => (string) ($item->title) ?: $youtubeId,
                    'hashtags' => $this->normalizeHashtags((array) ($item->hashtags ?? [])),
                    'storage_path' => (string) ($item->video_path),
                    'storage_size_bytes' => 0,
                    'downloaded_at' => $item->downloaded_at
                        ?->timezone((string) config('app.timezone', 'America/Sao_Paulo'))
                        ->format('d/m/Y H:i') ?? '—',
                    'download_status' => 'imported',
                    'dispatch_status' => 'local',
                    'post' => $post,
                    'can_post' => ! $post instanceof TiktokPost
                        || ! in_array($post->status, TiktokPost::ACTIVE_STATUSES, true),
                ];
            })
            ->all());
    }

    /**
     * @param  array<int|string, mixed>  $hashtags
     * @return array<int, string>
     */
    private function normalizeHashtags(array $hashtags): array
    {
        return array_values(array_filter(
            array_map(
                static fn (mixed $tag): string => is_string($tag) ? mb_trim($tag) : '',
                $hashtags,
            ),
            static fn (string $tag): bool => $tag !== '',
        ));
    }

    private function clearPendingTiktokPost(): void
    {
        $this->showTiktokConfirmation = false;
        $this->pendingYoutubeId = '';
        $this->pendingTitle = '';
        $this->pendingHashtags = [];
    }
}
