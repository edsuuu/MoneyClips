<?php

declare(strict_types=1);

namespace App\Livewire\Downloads;

use App\Livewire\Concerns\WithToasts;
use App\Models\TiktokPost;
use App\Services\DownloadYoutube\DownloadYoutubeService;
use App\Services\TiktokPost\TiktokPostService;
use App\Support\Cast;
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

    public ?string $loadError = null;

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
        $page = max(1, Cast::int($this->getPage()));
        $response = ['total' => 0, 'items' => []];
        $this->loadError = null;

        try {
            $response = resolve(DownloadYoutubeService::class)->listItems(
                self::PER_PAGE,
                ($page - 1) * self::PER_PAGE,
            );
        } catch (Throwable $throwable) {
            report($throwable);
            $this->loadError = 'Não foi possível carregar o estoque do microserviço download-youtube.';
        }

        $items = $this->decorateItems($response['items']);

        return view('livewire.downloads.index', [
            'items' => new LengthAwarePaginator(
                $items,
                $response['total'],
                self::PER_PAGE,
                $page,
                ['path' => request()->url()],
            ),
            'counts' => [
                'downloaded' => $response['total'],
                'queued' => TiktokPost::query()->whereIn('status', ['queued', 'processing'])->count(),
                'posted' => TiktokPost::query()->where('status', 'completed')->count(),
                'failed' => TiktokPost::query()->where('status', 'failed')->count(),
            ],
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    private function decorateItems(array $items): array
    {
        $youtubeIds = collect($items)
            ->map(static fn (array $item): string => Cast::str($item['youtube_id'] ?? ''))
            ->filter()
            ->values();

        /** @var Collection<string, TiktokPost> $posts */
        $posts = TiktokPost::query()
            ->whereIn('youtube_id', $youtubeIds)
            ->latest('id')
            ->get()
            ->unique('youtube_id')
            ->keyBy('youtube_id');

        return array_map(
            function (array $item) use ($posts): array {
                $youtubeId = Cast::str($item['youtube_id'] ?? '');
                $post = $posts->get($youtubeId);

                return [
                    'youtube_id' => $youtubeId,
                    'title' => Cast::str($item['title'] ?? '') ?: $youtubeId,
                    'hashtags' => $this->normalizeHashtags(Cast::arr($item['hashtags'] ?? [])),
                    'storage_path' => Cast::str($item['storage_path'] ?? ''),
                    'storage_size_bytes' => Cast::int($item['storage_size_bytes'] ?? 0),
                    'download_status' => Cast::str($item['status'] ?? ''),
                    'dispatch_status' => Cast::str($item['dispatch_status'] ?? ''),
                    'post' => $post,
                    'can_post' => ! $post instanceof TiktokPost
                        || ! in_array($post->status, TiktokPost::ACTIVE_STATUSES, true),
                ];
            },
            $items,
        );
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
}
