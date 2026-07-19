<?php

declare(strict_types=1);

namespace App\Livewire\Videos;

use App\Jobs\PostSlotToPlatformJob;
use App\Livewire\Concerns\WithToasts;
use App\Models\PlatformSetting;
use App\Models\ProcessingJob;
use App\Models\ScheduleSlot;
use App\Models\SocialPost;
use App\Models\YoutubeShort;
use App\Services\DownloadShorts\DownloadShortsService;
use App\Services\Processing\VideoProcessingService;
use App\Support\Hashtags;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\View\View;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;
use Throwable;

final class Index extends Component
{
    use WithToasts;

    public const string TAB_AVAILABLE = 'available';

    public const string TAB_EDITOR = 'editor';

    public const string TAB_TEMPLATED = 'templated';

    public const string TAB_POSTED = 'posted';

    private const array TABS = [self::TAB_AVAILABLE, self::TAB_EDITOR, self::TAB_TEMPLATED, self::TAB_POSTED];

    // ponytail: sem paginação — grid com teto fixo. Estoque de projeto solo
    // fica nas dezenas; se passar de SECTION_LIMIT, o upgrade é WithPagination.
    private const int SECTION_LIMIT = 60;

    private const int CARD_TAG_LIMIT = 4;

    #[Url(as: 'tab', except: self::TAB_AVAILABLE)]
    public string $tab = self::TAB_AVAILABLE;

    // Modal de revisão (título/hashtags + preview).
    public ?int $editingId = null;

    public string $editTitle = '';

    public string $editHashtags = '';

    // Modal de postagem instantânea.
    public bool $showInstant = false;

    public ?int $instantShortId = null;

    /** @var list<string> */
    public array $instantPlatforms = [];

    // Modal de novo download por canal.
    public bool $showUpload = false;

    public string $channelUrl = '';

    // Modal "Agendar" (vídeo com template → slot vazio).
    public ?int $schedulingId = null;

    public function setTab(string $tab): void
    {
        $this->tab = in_array($tab, self::TABS, true) ? $tab : self::TAB_AVAILABLE;
    }

    #[On('template-queued')]
    public function onTemplateQueued(): void
    {
        $this->tab = self::TAB_TEMPLATED;
    }

    public function editInTemplateEditor(int $shortId): void
    {
        $this->tab = self::TAB_EDITOR;
        $this->dispatch('template-editor-select', shortId: $shortId);
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
        $this->toast('Vídeo pronto para entrar na agenda.');
    }

    public function startReencode(int $shortId): void
    {
        $short = YoutubeShort::query()->find($shortId);
        if (! $short instanceof YoutubeShort) {
            return;
        }

        try {
            resolve(VideoProcessingService::class)->startReencode($short);
            $this->toast('Reencode enfileirado — acompanhe o selo HQ no card.');
        } catch (Throwable $throwable) {
            $this->toast($throwable->getMessage(), 'danger');
        }
    }

    public function openInstant(?int $shortId = null): void
    {
        $this->instantShortId = $shortId;
        $this->instantPlatforms = $this->enabledPlatforms();
        $this->showInstant = true;
    }

    public function closeInstant(): void
    {
        $this->showInstant = false;
        $this->instantShortId = null;
    }

    public function selectInstantVideo(int $shortId): void
    {
        $this->instantShortId = $shortId;
    }

    public function toggleInstantPlatform(string $platform): void
    {
        $this->instantPlatforms = in_array($platform, $this->instantPlatforms, true)
            ? array_values(array_diff($this->instantPlatforms, [$platform]))
            : [...$this->instantPlatforms, $platform];
    }

    public function confirmInstant(): void
    {
        $short = $this->instantShortId !== null ? YoutubeShort::query()->find($this->instantShortId) : null;
        if (! $short instanceof YoutubeShort) {
            $this->toast('Escolha um vídeo para postar.', 'danger');

            return;
        }

        $platforms = array_values(array_intersect($this->instantPlatforms, $this->enabledPlatforms()));
        if ($platforms === []) {
            $this->toast('Escolha ao menos uma plataforma habilitada.', 'danger');

            return;
        }

        $queued = [];
        foreach ($platforms as $platform) {
            $active = SocialPost::query()
                ->where('platform', $platform)
                ->where('youtube_id', $short->youtube_id)
                ->active()
                ->exists();

            if ($active) {
                continue; // já em fila/postado nessa plataforma
            }

            dispatch(new PostSlotToPlatformJob(null, $platform, $short->id));
            $queued[] = $platform;
        }

        $this->closeInstant();
        $this->toast($queued === []
            ? 'Este vídeo já está em fila ou postado nas plataformas escolhidas.'
            : sprintf('Postagem enviada para: %s.', implode(', ', $queued)), $queued === [] ? 'danger' : 'success');
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

    public function openSchedule(int $shortId): void
    {
        $this->schedulingId = $shortId;
    }

    public function closeSchedule(): void
    {
        $this->schedulingId = null;
    }

    public function assignToSlot(int $slotId): void
    {
        $short = $this->schedulingId !== null ? YoutubeShort::query()->find($this->schedulingId) : null;
        $slot = ScheduleSlot::query()->find($slotId);

        if (! $short instanceof YoutubeShort || ! $slot instanceof ScheduleSlot || $slot->dispatched_at !== null || $slot->youtube_short_id !== null) {
            $this->toast('Slot indisponível — atualize a página.', 'danger');

            return;
        }

        if ($short->ready_at === null) {
            $short->forceFill(['ready_at' => now()]);
        }

        $short->save();
        $slot->forceFill(['youtube_short_id' => $short->id])->save();

        $this->schedulingId = null;
        $this->toast(sprintf('Agendado para %s às %s.', $slot->slot_date->format('d/m'), $slot->timeLabel()));
    }

    // ── Internos ─────────────────────────────────────────────────────────

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

    /** @return list<string> */
    private function enabledPlatforms(): array
    {
        return array_values(array_map(
            static fn ($platform): string => (string) $platform,
            PlatformSetting::query()->where('enabled', true)->pluck('platform')->all(),
        ));
    }

    /**
     * Badge de estado do card (label + classes prontas pro @class da view).
     *
     * @param  array<string, mixed>  $video
     * @return array{label: string, class: string, loading: bool}
     */
    private function statusBadge(array $video, string $section): array
    {
        return match (true) {
            $video['processing'] === ProcessingJob::TYPE_REENCODE => ['label' => 'Reencodando', 'class' => 'bg-sky-950/60 text-sky-400', 'loading' => true],
            $video['processing'] === ProcessingJob::TYPE_TEMPLATE => ['label' => 'Renderizando', 'class' => 'bg-violet-950/60 text-violet-300', 'loading' => true],
            $section === self::TAB_POSTED => ['label' => 'Postado', 'class' => 'bg-emerald-400/15 text-emerald-400', 'loading' => false],
            $video['templated'] => ['label' => 'Template', 'class' => 'bg-violet-400/15 text-violet-300', 'loading' => false],
            $video['ready'] => ['label' => 'Pronto', 'class' => 'bg-emerald-400/15 text-emerald-400', 'loading' => false],
            default => ['label' => 'Baixado', 'class' => 'bg-slate-800 text-slate-300', 'loading' => false],
        };
    }

    /**
     * @param  Collection<int, YoutubeShort>  $shorts
     * @param  \Illuminate\Support\Collection<int|string, ProcessingJob>  $pendingJobs
     * @return array<int, array<string, mixed>>
     */
    private function decorate(Collection $shorts, \Illuminate\Support\Collection $pendingJobs, string $section): array
    {
        return $shorts->map(function (YoutubeShort $short) use ($pendingJobs, $section): array {
            $pending = $pendingJobs->get($short->id);

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
                'processing' => $pending instanceof ProcessingJob ? $pending->type : null,
            ];

            $video['statusBadge'] = $this->statusBadge($video, $section);

            return $video;
        })->values()->all();
    }

    /** @return array<int, array{id: int, title: string, selected: bool}> */
    private function instantCandidates(): array
    {
        if (! $this->showInstant) {
            return [];
        }

        return YoutubeShort::query()
            ->whereNotNull('video_path')
            ->whereNull('posted_youtube_at')
            ->whereNull('posted_tiktok_at')
            ->latest('id')
            ->limit(24)
            ->get(['id', 'title', 'youtube_id'])
            ->map(fn (YoutubeShort $s): array => [
                'id' => $s->id,
                'title' => $s->title ?? $s->youtube_id,
                'selected' => $this->instantShortId === $s->id,
            ])
            ->values()->all();
    }

    /** @return array<int, array{id: int, label: string}> */
    private function emptySlots(): array
    {
        return ScheduleSlot::query()
            ->pending()
            ->whereNull('youtube_short_id')
            ->where('is_active', true)
            ->orderBy('slot_date')
            ->orderBy('slot_time')
            ->limit(21)
            ->get()
            ->map(fn (ScheduleSlot $slot): array => [
                'id' => $slot->id,
                'label' => $slot->slot_date->format('d/m').' às '.$slot->timeLabel(),
            ])
            ->values()->all();
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

        $pendingJobs = ProcessingJob::query()
            ->pending()
            ->get(['id', 'youtube_short_id', 'type', 'status'])
            ->keyBy('youtube_short_id');

        $renderingCount = $pendingJobs->where('type', ProcessingJob::TYPE_TEMPLATE)->count();

        $downloaded = $this->downloadedQuery()->latest('id')->limit(self::SECTION_LIMIT)->get();
        $ready = $this->readyQuery()->latest('ready_at')->limit(self::SECTION_LIMIT)->get();
        $templated = $this->templatedQuery()->latest('template_rendered_at')->limit(self::SECTION_LIMIT)->get();
        $posted = $this->postedQuery()->latest('posted_youtube_at')->limit(self::SECTION_LIMIT)->get();

        $counts = [
            'available' => $this->downloadedQuery()->count() + $this->readyQuery()->count(),
            'templated' => $this->templatedQuery()->count() + $renderingCount,
            'posted' => $this->postedQuery()->count(),
        ];

        $editing = $this->editingId !== null ? YoutubeShort::query()->find($this->editingId) : null;

        return view('livewire.videos.index', [
            'downloaded' => $this->decorate($downloaded, $pendingJobs, self::TAB_AVAILABLE),
            'ready' => $this->decorate($ready, $pendingJobs, self::TAB_AVAILABLE),
            'templated' => $this->decorate($templated, $pendingJobs, self::TAB_TEMPLATED),
            'posted' => $this->decorate($posted, $pendingJobs, self::TAB_POSTED),
            'tabs' => [
                ['key' => self::TAB_AVAILABLE, 'label' => 'Disponíveis', 'count' => $counts['available']],
                ['key' => self::TAB_EDITOR, 'label' => 'Editor de template', 'count' => '✎'],
                ['key' => self::TAB_TEMPLATED, 'label' => 'Com template', 'count' => $counts['templated']],
                ['key' => self::TAB_POSTED, 'label' => 'Postados', 'count' => $counts['posted']],
            ],
            'renderingCount' => $renderingCount,
            'editingVideo' => $editing,
            'editingUrl' => $editing instanceof YoutubeShort ? $editing->presignedUrl() : null,
            'instantCandidates' => $this->instantCandidates(),
            'platforms' => PlatformSetting::query()->where('enabled', true)->get(['platform', 'display_name'])
                ->map(fn (PlatformSetting $p): array => [
                    'platform' => $p->platform,
                    'name' => $p->display_name,
                    'selected' => in_array($p->platform, $this->instantPlatforms, true),
                ])
                ->values()->all(),
            'emptySlots' => $this->schedulingId !== null ? $this->emptySlots() : [],
        ]);
    }
}
