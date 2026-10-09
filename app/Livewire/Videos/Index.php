<?php

declare(strict_types=1);

namespace App\Livewire\Videos;

use App\Enums\PostStatusEnum;
use App\Enums\TranscriptionStatusEnum;
use App\Enums\VideoCutStatusEnum;
use App\Helpers\Hashtags;
use App\Jobs\EditCutWithAiJob;
use App\Livewire\Concerns\WithCurrentUser;
use App\Livewire\Concerns\WithPostScheduling;
use App\Livewire\Concerns\WithToasts;
use App\Models\SocialPost;
use App\Models\VideoCutEdit;
use App\Models\YoutubeShort;
use App\Services\DownloadYoutube\DownloadShortsService;
use App\Services\Posting\PostSchedulerService;
use Illuminate\Contracts\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;
use RuntimeException;
use Throwable;

final class Index extends Component
{
    use WithCurrentUser;
    use WithPostScheduling;
    use WithToasts;

    public const string TAB_AVAILABLE = 'available';

    public const string TAB_TEMPLATED = 'templated';

    public const string TAB_POSTED = 'posted';

    private const array TABS = [self::TAB_AVAILABLE, self::TAB_TEMPLATED, self::TAB_POSTED];

    private const int SECTION_LIMIT = 60;

    private const int CARD_TAG_LIMIT = 4;

    private const int MAX_CHANGE_LENGTH = 300;

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

        $this->authorize('update', $short);

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

        $this->authorize('update', $short);

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

        $this->authorize('update', $short);

        if (($short->hashtags ?? []) === []) {
            $this->toast('Defina as hashtags antes de marcar como pronto.', 'danger');

            return;
        }

        $short->forceFill(['ready_at' => now()])->save();

        try {
            $posts = resolve(PostSchedulerService::class)->autoSchedule($short);
        } catch (RuntimeException $runtimeException) {
            report($runtimeException);
            $posts = [];
        }

        if ($posts === []) {
            $this->toast('Vídeo marcado como pronto.');

            return;
        }

        $done = array_map(fn (SocialPost $post): array => ['platform' => $this->platformLabel($post->socialAccount->platform), 'when' => $this->whenLabel($post->scheduled_for)], $posts);
        $this->toast('Vídeo marcado como pronto. '.$this->scheduledToast($done));
    }

    /**
     * Refaz a edição com IA do Short com uma instrução, sem novo tracking. O
     * render sai na mesma edição, então o webhook substitui o vídeo do mesmo
     * Short e mantém título, hashtags e ready_at.
     */
    public function redo(int $shortId, string $change): void
    {
        $short = YoutubeShort::query()->find($shortId);
        if (! $short instanceof YoutubeShort) {
            return;
        }

        $this->authorize('update', $short);

        $change = mb_trim($change);

        if ($change === '' || mb_strlen($change) > self::MAX_CHANGE_LENGTH) {
            $this->toast('Descreva a mudança em até 300 caracteres.', 'danger');

            return;
        }

        $edit = VideoCutEdit::query()->where('youtube_short_id', $shortId)->whereNotNull('spec->ai_output')->first();

        if (! $edit instanceof VideoCutEdit) {
            $this->toast('Este Short não tem edição com IA para refazer.', 'danger');

            return;
        }

        // ponytail: mesmo claim e destravamento de 30 min do "Editar com IA" em
        // /meus-uploads; centralizar num método do model se surgir um 3º.
        $claimed = VideoCutEdit::query()
            ->whereKey($edit->id)
            ->where(fn (QueryBuilder $query): QueryBuilder => $query
                ->where('updated_at', '<', now()->subMinutes(30))
                ->orWhere(fn (QueryBuilder $idle): QueryBuilder => $idle
                    ->where(fn (QueryBuilder $ai): QueryBuilder => $ai->whereNull('ai_status')->orWhere('ai_status', '!=', TranscriptionStatusEnum::Processing->value))
                    ->where(fn (QueryBuilder $tracking): QueryBuilder => $tracking->whereNull('tracking_status')->orWhere('tracking_status', '!=', TranscriptionStatusEnum::Processing->value))
                    ->where(fn (QueryBuilder $render): QueryBuilder => $render->whereNull('render_status')->orWhere('render_status', '!=', VideoCutStatusEnum::Generating->value))))
            ->update(['ai_status' => TranscriptionStatusEnum::Processing, 'ai_error' => null]);

        if ($claimed !== 1) {
            $this->toast('Este Short já está sendo editado ou renderizado.', 'danger');

            return;
        }

        dispatch(new EditCutWithAiJob($edit->id, change: $change));
        $this->toast('Refazendo com IA — o Short é substituído aqui quando o render terminar.');
    }

    public function openUpload(): void
    {
        abort_unless($this->currentUser()->isAdmin(), 403);
        $this->channelUrl = '';
        $this->showUpload = true;
    }

    public function closeUpload(): void
    {
        $this->showUpload = false;
    }

    /**
     * Short de canal nasce sem dono (o webhook do media não sabe quem pediu):
     * só o admin, que enxerga o estoque inteiro, pode disparar.
     */
    public function startDownload(): void
    {
        abort_unless($this->currentUser()->isAdmin(), 403);
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

    /**
     * Imagem CC BY/BY-SA exige crédito na postagem: sai do spec da edição
     * que gerou o Short, uma linha por imagem.
     */
    private function credits(YoutubeShort $short): string
    {
        $spec = VideoCutEdit::query()->where('youtube_short_id', $short->id)->latest('id')->first()?->spec;
        $credits = array_filter(array_column($spec['images'] ?? [], 'credit'));

        return implode(PHP_EOL, array_unique($credits));
    }

    /** @return Builder<YoutubeShort> */
    private function downloadedQuery(): Builder
    {
        return YoutubeShort::query()->forUser($this->currentUser())
            ->whereNotNull('video_path')
            ->whereNull('ready_at')
            ->whereNull('posted_youtube_at')
            ->whereNull('posted_tiktok_at');
    }

    /** @return Builder<YoutubeShort> */
    private function readyQuery(): Builder
    {
        return YoutubeShort::query()->forUser($this->currentUser())
            ->whereNotNull('video_path')
            ->whereNotNull('ready_at')
            ->whereNull('template_rendered_at')
            ->whereNull('posted_youtube_at')
            ->whereNull('posted_tiktok_at');
    }

    /** @return Builder<YoutubeShort> */
    private function templatedQuery(): Builder
    {
        return YoutubeShort::query()->forUser($this->currentUser())
            ->whereNotNull('template_rendered_at')
            ->whereNull('posted_youtube_at')
            ->whereNull('posted_tiktok_at');
    }

    /** @return Builder<YoutubeShort> */
    private function postedQuery(): Builder
    {
        return YoutubeShort::query()->forUser($this->currentUser())->where(fn (Builder $q): Builder => $q
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
            $section === self::TAB_POSTED => ['label' => 'Postado', 'class' => 'bg-emerald-400/15 text-emerald-800 dark:text-emerald-400'],
            $video['templated'] => ['label' => 'Template', 'class' => 'bg-violet-400/15 text-violet-700 dark:text-violet-300'],
            $video['ready'] => ['label' => 'Pronto', 'class' => 'bg-emerald-400/15 text-emerald-800 dark:text-emerald-400'],
            default => ['label' => 'Baixado', 'class' => 'bg-slate-800 text-slate-300'],
        };
    }

    /**
     * @param  Collection<int, YoutubeShort>  $shorts
     * @return array<int, array<string, mixed>>
     */
    private function decorate(Collection $shorts, string $section): array
    {
        $edits = VideoCutEdit::query()
            ->whereIn('youtube_short_id', $shorts->pluck('id'))
            ->whereNotNull('spec->ai_output')
            ->get(['id', 'youtube_short_id', 'ai_status', 'ai_error', 'render_status', 'render_error'])
            ->keyBy('youtube_short_id');

        $scheduling = $section === self::TAB_AVAILABLE ? $shorts->whereNotNull('ready_at') : new Collection;

        $posts = SocialPost::query()
            ->forUser($this->currentUser())
            ->with(['youtubeShort', 'socialAccount'])
            ->whereIn('youtube_short_id', $scheduling->modelKeys())
            ->where('status', '!=', PostStatusEnum::Canceled)
            ->oldest('scheduled_for')
            ->get()
            ->groupBy('youtube_short_id');

        $schedulable = $scheduling->isEmpty() ? [] : $this->schedulableShortIds($scheduling);

        return $shorts->map(function (YoutubeShort $short) use ($section, $edits, $posts, $schedulable): array {
            $edit = $section === self::TAB_POSTED ? null : $edits->get($short->id);

            $video = [
                'id' => $short->id,
                'subtitle' => is_null($short->duration_seconds) ? $short->youtube_id : sprintf('%d:%02d', intdiv($short->duration_seconds, 60), $short->duration_seconds % 60),
                'title' => $short->title ?? $short->youtube_id,
                'displayTags' => array_slice($short->hashtags ?? [], 0, self::CARD_TAG_LIMIT),
                'ready' => $short->ready_at !== null,
                'templated' => $short->template_rendered_at !== null,
                'reencoded' => $short->processed_video_path !== null && $short->template_rendered_at === null,
                'posted_youtube' => $short->posted_youtube_at !== null,
                'posted_tiktok' => $short->posted_tiktok_at !== null,
                'youtube_link' => $short->youtube_video_id !== null ? 'https://www.youtube.com/shorts/'.$short->youtube_video_id : null,
                'canRedo' => $edit instanceof VideoCutEdit,
                'isRedoing' => $edit?->ai_status === TranscriptionStatusEnum::Processing || $edit?->render_status === VideoCutStatusEnum::Generating,
                'redoError' => $this->redoError($edit),
                'posts' => $posts->get($short->id, new Collection)->map($this->postRow(...))->values()->all(),
                'canSchedule' => in_array($short->id, $schedulable, true),
            ];

            $video['statusBadge'] = $this->statusBadge($video, $section);

            return $video;
        })->values()->all();
    }

    private function redoError(?VideoCutEdit $edit): ?string
    {
        if (is_null($edit) || $edit->ai_status === TranscriptionStatusEnum::Processing) {
            return null;
        }

        if ($edit->ai_status === TranscriptionStatusEnum::Failed) {
            return $edit->ai_error;
        }

        if ($edit->render_status === VideoCutStatusEnum::Failed) {
            return $edit->render_error;
        }

        return null;
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

        $editing = $this->editingId !== null ? YoutubeShort::query()->forUser($this->currentUser())->find($this->editingId) : null;
        $downloadedCards = $this->decorate($downloaded, self::TAB_AVAILABLE);
        $readyCards = $this->decorate($ready, self::TAB_AVAILABLE);
        $templatedCards = $this->decorate($templated, self::TAB_TEMPLATED);

        return view('livewire.videos.index', [
            'downloaded' => $downloadedCards,
            'ready' => $readyCards,
            'templated' => $templatedCards,
            'posted' => $this->decorate($posted, self::TAB_POSTED),
            'isRedoing' => in_array(true, array_column([...$downloadedCards, ...$readyCards, ...$templatedCards], 'isRedoing'), true),
            'tabs' => [
                ['key' => self::TAB_AVAILABLE, 'label' => 'Disponíveis', 'count' => $counts['available']],
                ['key' => self::TAB_TEMPLATED, 'label' => 'Com template', 'count' => $counts['templated']],
                ['key' => self::TAB_POSTED, 'label' => 'Postados', 'count' => $counts['posted']],
            ],
            'canDownload' => $this->currentUser()->isAdmin(),
            'editingVideo' => $editing,
            'editingUrl' => $editing instanceof YoutubeShort ? $editing->presignedUrl() : null,
            'editingCredits' => $editing instanceof YoutubeShort ? $this->credits($editing) : '',
            'scheduleModal' => $this->scheduleModal(),
        ]);
    }
}
