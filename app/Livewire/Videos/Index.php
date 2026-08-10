<?php

declare(strict_types=1);

namespace App\Livewire\Videos;

use App\Helpers\Hashtags;
use App\Livewire\Concerns\WithToasts;
use App\Models\YoutubeShort;
use App\Services\DownloadYoutube\DownloadShortsService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;
use Throwable;

final class Index extends Component
{
    use WithToasts;

    public const string TAB_AVAILABLE = 'available';

    public const string TAB_TEMPLATED = 'templated';

    public const string TAB_POSTED = 'posted';

    private const array TABS = [self::TAB_AVAILABLE, self::TAB_TEMPLATED, self::TAB_POSTED];

    private const int SECTION_LIMIT = 60;

    private const int CARD_TAG_LIMIT = 4;

    #[Url(as: 'tab', except: self::TAB_AVAILABLE)]
    public string $tab = self::TAB_AVAILABLE;

    public ?int $editingId = null;

    public string $editTitle = '';

    public string $editHashtags = '';

    public bool $showUpload = false;

    public string $channelUrl = '';

    public function setTab(string $tab): void
    {
        $this->tab = in_array($tab, self::TABS, true) ? $tab : self::TAB_AVAILABLE;
    }

    public function openEdit(int $shortId): void
    {
        $short = YoutubeShort::query()->find($shortId);
        if (! $short instanceof YoutubeShort) {
            return;
        }

        $this->editingId = $short->id;
        $this->editTitle = $short->title ?? '';
        $this->editHashtags = Hashtags::toInput($short->hashtags);
    }

    public function closeEdit(): void
    {
        $this->editingId = null;
    }

    public function saveEdit(): void
    {
        $short = $this->editingId !== null ? YoutubeShort::query()->find($this->editingId) : null;
        if (! $short instanceof YoutubeShort) {
            return;
        }

        $title = mb_trim($this->editTitle);
        $short->title = $title === '' ? $short->title : $title;
        $short->hashtags = Hashtags::parse($this->editHashtags);
        $short->save();

        $this->editingId = null;
        $this->toast('Vídeo atualizado.');
    }

    public function markReady(int $shortId): void
    {
        $short = YoutubeShort::query()->find($shortId);
        if (! $short instanceof YoutubeShort) {
            return;
        }

        if (($short->hashtags ?? []) === []) {
            $this->toast('Defina as hashtags antes de marcar como pronto.', 'danger');

            return;
        }

        $short->forceFill(['ready_at' => now()])->save();
        $this->toast('Vídeo marcado como pronto.');
    }

    public function openUpload(): void
    {
        $this->channelUrl = '';
        $this->showUpload = true;
    }

    public function closeUpload(): void
    {
        $this->showUpload = false;
    }

    public function startDownload(): void
    {
        $this->validate();

        try {
            $count = resolve(DownloadShortsService::class)->createDownload($this->channelUrl);
            $this->showUpload = false;
            $this->toast(sprintf('Download iniciado: %d Shorts na fila do canal.', $count));
        } catch (Throwable $throwable) {
            report($throwable);
            $this->toast('Não foi possível iniciar o download: '.$throwable->getMessage(), 'danger');
        }
    }

    /** @return Builder<YoutubeShort> */
    private function downloadedQuery(): Builder
    {
        return YoutubeShort::query()
            ->whereNotNull('video_path')
            ->whereNull('ready_at')
            ->whereNull('posted_youtube_at')
            ->whereNull('posted_tiktok_at');
    }

    /** @return Builder<YoutubeShort> */
    private function readyQuery(): Builder
    {
        return YoutubeShort::query()
            ->whereNotNull('video_path')
            ->whereNotNull('ready_at')
            ->whereNull('template_rendered_at')
            ->whereNull('posted_youtube_at')
            ->whereNull('posted_tiktok_at');
    }

    /** @return Builder<YoutubeShort> */
    private function templatedQuery(): Builder
    {
        return YoutubeShort::query()
            ->whereNotNull('template_rendered_at')
            ->whereNull('posted_youtube_at')
            ->whereNull('posted_tiktok_at');
    }

    /** @return Builder<YoutubeShort> */
    private function postedQuery(): Builder
    {
        return YoutubeShort::query()->where(fn (Builder $q): Builder => $q
            ->whereNotNull('posted_youtube_at')
            ->orWhereNotNull('posted_tiktok_at'));
    }

    /**
     * Badge de estado do card (label + classes prontas pro @class da view).
     *
     * @param  array<string, mixed>  $video
     * @return array{label: string, class: string}
     */
    private function statusBadge(array $video, string $section): array
    {
        return match (true) {
            $section === self::TAB_POSTED => ['label' => 'Postado', 'class' => 'bg-emerald-400/15 text-emerald-400'],
            $video['templated'] => ['label' => 'Template', 'class' => 'bg-violet-400/15 text-violet-300'],
            $video['ready'] => ['label' => 'Pronto', 'class' => 'bg-emerald-400/15 text-emerald-400'],
            default => ['label' => 'Baixado', 'class' => 'bg-slate-800 text-slate-300'],
        };
    }

    /**
     * @param  Collection<int, YoutubeShort>  $shorts
     * @return array<int, array<string, mixed>>
     */
    private function decorate(Collection $shorts, string $section): array
    {
        return $shorts->map(function (YoutubeShort $short) use ($section): array {
            $video = [
                'id' => $short->id,
                'youtube_id' => $short->youtube_id,
                'title' => $short->title ?? $short->youtube_id,
                'displayTags' => array_slice($short->hashtags ?? [], 0, self::CARD_TAG_LIMIT),
                'ready' => $short->ready_at !== null,
                'templated' => $short->template_rendered_at !== null,
                'reencoded' => $short->processed_video_path !== null && $short->template_rendered_at === null,
                'posted_youtube' => $short->posted_youtube_at !== null,
                'posted_tiktok' => $short->posted_tiktok_at !== null,
                'youtube_link' => $short->youtube_video_id !== null ? 'https://www.youtube.com/shorts/'.$short->youtube_video_id : null,
            ];

            $video['statusBadge'] = $this->statusBadge($video, $section);

            return $video;
        })->values()->all();
    }

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
            'channelUrl.url' => 'Informe uma URL válida.',
        ];
    }

    /** @return array<string, string> */
    public function validationAttributes(): array
    {
        return ['channelUrl' => 'URL do canal'];
    }

    public function render(): View
    {
        if (! in_array($this->tab, self::TABS, true)) {
            $this->tab = self::TAB_AVAILABLE;
        }

        $downloaded = $this->downloadedQuery()->latest('id')->limit(self::SECTION_LIMIT)->get();
        $ready = $this->readyQuery()->latest('ready_at')->limit(self::SECTION_LIMIT)->get();
        $templated = $this->templatedQuery()->latest('template_rendered_at')->limit(self::SECTION_LIMIT)->get();
        $posted = $this->postedQuery()->latest('posted_youtube_at')->limit(self::SECTION_LIMIT)->get();

        $counts = [
            'available' => $this->downloadedQuery()->count() + $this->readyQuery()->count(),
            'templated' => $this->templatedQuery()->count(),
            'posted' => $this->postedQuery()->count(),
        ];

        $editing = $this->editingId !== null ? YoutubeShort::query()->find($this->editingId) : null;

        return view('livewire.videos.index', [
            'downloaded' => $this->decorate($downloaded, self::TAB_AVAILABLE),
            'ready' => $this->decorate($ready, self::TAB_AVAILABLE),
            'templated' => $this->decorate($templated, self::TAB_TEMPLATED),
            'posted' => $this->decorate($posted, self::TAB_POSTED),
            'tabs' => [
                ['key' => self::TAB_AVAILABLE, 'label' => 'Disponíveis', 'count' => $counts['available']],
                ['key' => self::TAB_TEMPLATED, 'label' => 'Com template', 'count' => $counts['templated']],
                ['key' => self::TAB_POSTED, 'label' => 'Postados', 'count' => $counts['posted']],
            ],
            'editingVideo' => $editing,
            'editingUrl' => $editing instanceof YoutubeShort ? $editing->presignedUrl() : null,
        ]);
    }
}
