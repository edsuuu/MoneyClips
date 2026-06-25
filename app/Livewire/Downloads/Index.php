<?php

declare(strict_types=1);

namespace App\Livewire\Downloads;

use App\Livewire\Concerns\WithToasts;
use App\Models\SocialPost;
use App\Models\YoutubeShort;
use App\Services\TikTok\TiktokPostService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Throwable;

final class Index extends Component
{
    use WithPagination;
    use WithToasts;

    public const string TAB_AVAILABLE = 'available';

    public const string TAB_QUEUED = 'queued';

    public const string TAB_POSTED = 'posted';

    public const string TAB_FAILED = 'failed';

    private const int PER_PAGE = 15;

    private const int PRESIGNED_TTL_MINUTES = 30;

    private const array TABS = [
        self::TAB_AVAILABLE,
        self::TAB_QUEUED,
        self::TAB_POSTED,
        self::TAB_FAILED,
    ];

    #[Url(as: 'tab', except: self::TAB_AVAILABLE)]
    public string $tab = self::TAB_AVAILABLE;

    public bool $showTiktokConfirmation = false;

    public string $pendingYoutubeId = '';

    public string $pendingTitle = '';

    /** @var array<int, string> */
    public array $pendingHashtags = [];

    public function setTab(string $tab): void
    {
        if (! in_array($tab, self::TABS, true)) {
            return;
        }

        $this->tab = $tab;
        $this->resetPage();
    }

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

        $existing = SocialPost::query()->where('platform', SocialPost::PLATFORM_TIKTOK)
            ->where('youtube_id', $youtubeId)
            ->whereIn('status', SocialPost::ACTIVE_STATUSES)
            ->latest('id')
            ->first();

        if ($existing instanceof SocialPost) {
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
        if (! in_array($this->tab, self::TABS, true)) {
            $this->tab = self::TAB_AVAILABLE;
        }

        $page = max(1, (int) ($this->getPage()));
        $shorts = $this->queryForTab($this->tab)
            ->latest('youtube_shorts.id')
            ->paginate(self::PER_PAGE, ['youtube_shorts.*'], 'page', $page);

        $items = new LengthAwarePaginator(
            $this->decorateItems($shorts->getCollection()),
            $shorts->total(),
            self::PER_PAGE,
            $shorts->currentPage(),
            ['path' => request()->url()],
        );

        return view('livewire.downloads.index', [
            'items' => $items,
            'tab' => $this->tab,
            'counts' => [
                'available' => $this->queryForTab(self::TAB_AVAILABLE)->count(),
                'queued' => $this->queryForTab(self::TAB_QUEUED)->count(),
                'posted' => $this->queryForTab(self::TAB_POSTED)->count(),
                'failed' => $this->queryForTab(self::TAB_FAILED)->count(),
            ],
        ]);
    }

    /**
     * @return Builder<YoutubeShort>
     */
    private function queryForTab(string $tab): Builder
    {
        $base = YoutubeShort::query()->whereNotNull('video_path');

        return match ($tab) {
            self::TAB_QUEUED => $base->whereIn(
                'youtube_id',
                SocialPost::query()->where('platform', SocialPost::PLATFORM_TIKTOK)->select('youtube_id')->whereIn('status', ['queued', 'processing']),
            ),
            self::TAB_POSTED => $base->whereIn(
                'youtube_id',
                SocialPost::query()->where('platform', SocialPost::PLATFORM_TIKTOK)->select('youtube_id')->whereIn('status', ['completed', 'dry-run']),
            ),
            self::TAB_FAILED => $base->whereIn(
                'youtube_id',
                SocialPost::query()->where('platform', SocialPost::PLATFORM_TIKTOK)->select('youtube_id')->where('status', 'failed'),
            ),
            default => $base->whereNotIn(
                'youtube_id',
                // Vídeos já postados / em fila / em processamento somem da listagem geral.
                SocialPost::query()->where('platform', SocialPost::PLATFORM_TIKTOK)->select('youtube_id')->whereIn(
                    'status',
                    ['completed', 'dry-run', 'queued', 'processing'],
                ),
            ),
        };
    }

    /**
     * @param  Collection<int, YoutubeShort>  $items
     * @return list<array<string, mixed>>
     */
    private function decorateItems(Collection $items): array
    {
        $youtubeIds = $items->pluck('youtube_id')->filter()->values();

        /** @var Collection<string, SocialPost> $posts */
        $posts = SocialPost::query()->where('platform', SocialPost::PLATFORM_TIKTOK)
            ->whereIn('youtube_id', $youtubeIds)
            ->latest('id')
            ->get()
            ->unique('youtube_id')
            ->keyBy('youtube_id');

        return array_values($items
            ->map(function (YoutubeShort $item) use ($posts): array {
                $youtubeId = $item->youtube_id;
                $post = $posts->get($youtubeId);
                $storagePath = (string) ($item->video_path);

                return [
                    'youtube_id' => $youtubeId,
                    'title' => (string) ($item->title) ?: $youtubeId,
                    'hashtags' => $this->normalizeHashtags((array) ($item->hashtags ?? [])),
                    'storage_path' => $storagePath,
                    'storage_url' => $this->presignedUrl($storagePath),
                    'downloaded_at' => $item->downloaded_at
                        ?->timezone((string) config('app.timezone', 'America/Sao_Paulo'))
                        ->format('d/m/Y H:i') ?? '—',
                    'post' => $post,
                    'post_error' => $post?->error,
                    'post_status' => $post?->status,
                    'can_post' => ! $post instanceof SocialPost
                        || ! in_array($post->status, SocialPost::ACTIVE_STATUSES, true),
                ];
            })
            ->all());
    }

    private function presignedUrl(string $path): ?string
    {
        if ($path === '') {
            return null;
        }

        try {
            return Storage::disk('s3')->temporaryUrl(
                $path,
                Date::now()->addMinutes(self::PRESIGNED_TTL_MINUTES),
            );
        } catch (Throwable) {
            return null;
        }
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
