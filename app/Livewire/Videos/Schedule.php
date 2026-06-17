<?php

declare(strict_types=1);

namespace App\Livewire\Videos;

use App\Livewire\Concerns\WithToasts;
use App\Models\Cut;
use App\Models\ScheduledPost;
use App\Models\SocialAccount;
use App\Models\Video;
use App\Services\Youtube\PostDraftBuilder;
use App\Services\Youtube\YoutubePublisher;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Date;
use Illuminate\View\View;
use Livewire\Component;
use Throwable;

final class Schedule extends Component
{
    use WithToasts;

    public Video $video;

    /** @var array<int, string> Cut uuids selecionados (a ordem de postagem segue o index do corte). */
    public array $selectedCuts = [];

    /** @var array<string, array{title: string, description: string, hashtags: string}> Metadados por corte (uuid). */
    public array $cutMeta = [];

    /** @var array<string, bool> */
    public array $editingCuts = [];

    /** @var array<string, string> */
    public array $cutPublishModes = [];

    /** @var array<string, string> */
    public array $cutPublishAt = [];

    /** @var array<string, bool> */
    public array $cutPublishAuto = [];

    /** @var array<string, int> */
    public array $cutScheduleGapHours = [];

    public function mount(Video $video, PostDraftBuilder $draftBuilder): void
    {
        $this->video = $video;
        $this->video->load('cuts');
        foreach ($this->video->cuts as $cut) {
            $draft = $draftBuilder->forCut($this->video, $cut);
            $this->cutMeta[$cut->uuid] = [
                'title' => $draft['title'],
                'description' => $draft['description'],
                'hashtags' => $this->stringifyHashtags($draft['hashtags']),
            ];
            $this->editingCuts[$cut->uuid] = false;
            $this->cutScheduleGapHours[$cut->uuid] = 2;
        }

        $this->hydrateQuickFlowFromRequest();
        $this->normalizeCutSchedulingPlan();
    }

    public function confirmPublications(): void
    {
        $this->normalizeCutSchedulingPlan();
        $this->processPublications();
    }

    public function updatedCutPublishModes(string $value, string $key): void
    {
        if (! array_key_exists($key, $this->cutPublishModes)) {
            return;
        }

        $this->cutPublishModes[$key] = in_array($value, ['now', 'scheduled'], true) ? $value : 'now';
        $this->cutPublishAuto[$key] = true;

        $this->normalizeCutSchedulingPlan();
    }

    public function updatedCutPublishAt(string $value, string $key): void
    {
        if (! array_key_exists($key, $this->cutPublishAt)) {
            return;
        }

        $this->cutPublishAuto[$key] = false;
        $this->normalizeCutSchedulingPlan();
    }

    public function updatedCutScheduleGapHours(string $value, string $key): void
    {
        if (! array_key_exists($key, $this->cutScheduleGapHours)) {
            return;
        }

        $this->cutScheduleGapHours[$key] = in_array((int) $value, [1, 2, 3, 4, 5, 6], true) ? (int) $value : 2;
        $this->normalizeCutSchedulingPlan();
    }

    public function refreshSchedulingPlan(): void
    {
        $this->normalizeCutSchedulingPlan();
    }

    public function render(): View
    {
        $this->video->refresh()->load('cuts.files');

        $accounts = SocialAccount::query()
            ->where('is_active', true)
            ->where('user_id', Auth::id())
            ->orderBy('platform')
            ->orderBy('name')
            ->get()
            ->groupBy('platform');

        $publishedTargetsByCut = $this->publishedTargetsByCut();

        $hasYoutubeAccount = ($accounts['youtube'] ?? collect())->isNotEmpty();

        $this->normalizeCutSchedulingPlan();

        if ($this->selectedCuts !== []) {
            $this->selectedCuts = array_values(array_filter(
                $this->selectedCuts,
                fn (string $uuid): bool => ! $this->isCutPublishedOnYoutube($uuid, $publishedTargetsByCut) && $hasYoutubeAccount,
            ));
        }

        return view('livewire.videos.schedule', [
            'cuts' => $this->video->cuts,
            'platformLabels' => [YoutubePublisher::PLATFORM => YoutubePublisher::LABEL],
            'accountsByPlatform' => $accounts,
            'publishedTargetsByCut' => $publishedTargetsByCut,
        ]);
    }

    public function toggleCutEdit(string $uuid): void
    {
        if (! array_key_exists($uuid, $this->cutMeta)) {
            return;
        }

        $this->editingCuts[$uuid] = ! (bool) ($this->editingCuts[$uuid] ?? false);
    }

    public function saveCutMeta(string $uuid): void
    {
        if (! array_key_exists($uuid, $this->cutMeta)) {
            return;
        }

        $this->editingCuts[$uuid] = false;
        $this->toast('Legenda/descrição salvas.');
    }

    private function processPublications(): void
    {
        if ($this->selectedCuts === []) {
            $this->toast('Selecione ao menos um corte.', 'danger');

            return;
        }

        // Cortes na ordem de publicação (segue o index do corte).
        /** @var Collection<int, Cut> $orderedCuts */
        $orderedCuts = $this->video->cuts()
            ->whereIn('uuid', $this->selectedCuts)
            ->orderBy('index')
            ->get();

        if ($orderedCuts->isEmpty()) {
            $this->toast('Nenhum corte válido selecionado.', 'danger');

            return;
        }

        $created = 0;
        $sequence = $this->startingYoutubeSequence();

        $account = SocialAccount::query()
            ->where('user_id', Auth::id())
            ->where('platform', 'youtube')
            ->where('is_active', true)
            ->orderBy('name')
            ->first();

        if (! $account instanceof SocialAccount) {
            $label = YoutubePublisher::LABEL;
            $this->toast(sprintf('Conecte uma conta de %s antes de publicar.', $label), 'danger');

            return;
        }

        $publishedTargetsByCut = $this->publishedTargetsByCut();

        foreach ($orderedCuts as $cut) {
            if ($this->isCutPublishedOnYoutube($cut->uuid, $publishedTargetsByCut)) {
                $this->toast(sprintf(
                    'O corte %s já tem publicação no YouTube.',
                    $cut->name ?? $cut->uuid,
                ), 'danger');

                return;
            }

            $meta = $this->metaFor($cut);
            $mode = $this->cutPublishModes[$cut->uuid] ?? 'now';
            $scheduledFor = $this->parseLocalDateTime($this->cutPublishAt[$cut->uuid] ?? '') ?? Date::now();
            $isImmediate = $mode !== 'scheduled';

            if ($this->hasExistingPublicationForCutPlatformAccount($cut->id, 'youtube', $account->id)) {
                $label = YoutubePublisher::LABEL;
                $this->toast(sprintf(
                    'O corte %s já foi publicado em %s usando a conta %s.',
                    $cut->name ?? $cut->uuid,
                    $label,
                    (string) ($account->name)
                ), 'danger');

                return;
            }

            $post = ScheduledPost::query()->create([
                'video_id' => $this->video->id,
                'cut_id' => $cut->id,
                'social_account_id' => $account->id,
                'platform' => 'youtube',
                'sequence' => $sequence++,
                'title' => $meta['title'] !== '' ? $meta['title'] : ($cut->name ?? null),
                'description' => $meta['description'],
                'hashtags' => $meta['hashtags'],
                'scheduled_for' => $scheduledFor,
                'status' => $isImmediate ? ScheduledPost::STATUS_PUBLISHING : ScheduledPost::STATUS_SCHEDULED,
                'created_by' => Auth::id(),
            ]);

            $post->log(
                'info',
                $isImmediate
                    ? sprintf('Envio imediato solicitado em %s.', (string) ($account->name))
                    : sprintf('Agendado para %s em %s.', $scheduledFor->format('d/m/Y H:i'), (string) ($account->name))
            );

            $created++;
        }
    }

    /**
     * @return array{title: string, description: string, hashtags: list<string>}
     */
    private function metaFor(Cut $cut): array
    {
        $meta = $this->cutMeta[$cut->uuid] ?? [];

        return [
            'title' => mb_trim((string) ($meta['title'] ?? '')),
            'description' => mb_trim((string) ($meta['description'] ?? '')),
            'hashtags' => $this->parseHashtags((string) ($meta['hashtags'] ?? '')),
        ];
    }

    /**
     * @param  mixed  $hashtags
     */
    private function stringifyHashtags($hashtags): string
    {
        if (! is_array($hashtags)) {
            return '';
        }

        return implode(' ', array_map(static fn ($t): string => '#'.mb_ltrim((string) ($t), '#'), $hashtags));
    }

    /**
     * @return list<string>
     */
    private function parseHashtags(string $raw): array
    {
        $parts = preg_split('/[\s,]+/', $raw) ?: [];

        return array_values(array_filter(array_map(
            static fn (string $t): string => mb_ltrim(mb_trim($t), '#'),
            $parts,
        ), static fn (string $t): bool => $t !== ''));
    }

    private function hydrateQuickFlowFromRequest(): void
    {
        $queryCuts = mb_trim((string) request()->query('cuts', ''));

        if ($queryCuts !== '') {
            $validUuids = $this->video->cuts->map(fn (Cut $cut): string => (string) ($cut->uuid))->all();
            $selected = array_values(array_intersect(
                preg_split('/[\s,]+/', $queryCuts) ?: [],
                $validUuids,
            ));

            if ($selected !== []) {
                $this->selectedCuts = $selected;
            }
        }
    }

    private function startingYoutubeSequence(): int
    {
        $max = ScheduledPost::query()
            ->where('video_id', $this->video->id)
            ->where('platform', 'youtube')
            ->max('sequence');

        return (is_numeric($max) ? (int) $max : 0) + 1;
    }

    /**
     * @return array<string, array<string, bool>>
     */
    private function publishedTargetsByCut(): array
    {
        $posts = ScheduledPost::query()
            ->where('video_id', $this->video->id)
            ->whereNotNull('cut_id')
            ->whereIn('status', [
                ScheduledPost::STATUS_PENDING,
                ScheduledPost::STATUS_SCHEDULED,
                ScheduledPost::STATUS_PUBLISHING,
                ScheduledPost::STATUS_POSTED,
            ])
            ->with('cut:id,uuid')
            ->get();

        $targets = [];

        foreach ($posts as $post) {
            $cutUuid = $post->cut?->uuid;
            if (! is_string($cutUuid)) {
                continue;
            }

            if ($cutUuid === '') {
                continue;
            }

            $targets[$cutUuid][$post->platform] = true;
        }

        return $targets;
    }

    /**
     * @param  array<string, array<string, bool>>  $publishedTargetsByCut
     */
    private function isCutPublishedOnYoutube(string $cutUuid, array $publishedTargetsByCut = []): bool
    {
        $published = $publishedTargetsByCut[$cutUuid] ?? [];

        return (bool) ($published['youtube'] ?? false);
    }

    private function hasExistingPublicationForCutPlatformAccount(int $cutId, string $platform, int $accountId): bool
    {
        return ScheduledPost::query()
            ->where('cut_id', $cutId)
            ->where('platform', $platform)
            ->where('social_account_id', $accountId)
            ->whereIn('status', [
                ScheduledPost::STATUS_PENDING,
                ScheduledPost::STATUS_SCHEDULED,
                ScheduledPost::STATUS_PUBLISHING,
                ScheduledPost::STATUS_POSTED,
            ])
            ->exists();
    }

    private function normalizeCutSchedulingPlan(): void
    {
        $previousEffectiveAt = Date::now();
        $previousGapHours = 2;

        foreach ($this->video->cuts()->orderBy('index')->get() as $index => $cut) {
            $uuid = $cut->uuid;
            $currentGapHours = $this->gapHoursForCut($uuid);

            $mode = $this->cutPublishModes[$uuid] ?? ($index === 0 ? 'now' : 'scheduled');
            if (! in_array($mode, ['now', 'scheduled'], true)) {
                $mode = $index === 0 ? 'now' : 'scheduled';
            }

            $this->cutPublishModes[$uuid] = $mode;

            if ($mode === 'now') {
                $this->cutPublishAuto[$uuid] = true;
                $this->cutPublishAt[$uuid] = Date::now()->format('Y-m-d\TH:i');
                $previousEffectiveAt = Date::now();
                $previousGapHours = $currentGapHours;

                continue;
            }

            $currentScheduledAt = $this->parseLocalDateTime($this->cutPublishAt[$uuid] ?? '');
            $isAuto = $this->cutPublishAuto[$uuid] ?? true;

            if (! $currentScheduledAt instanceof CarbonImmutable || $isAuto) {
                // $mode aqui é sempre 'scheduled' ('now' deu continue acima).
                if ($index === 0) {
                    $currentScheduledAt = Date::now()->addHours($currentGapHours);
                } else {
                    $currentScheduledAt = $previousEffectiveAt->copy()->addHours($previousGapHours);
                }

                $this->cutPublishAt[$uuid] = $currentScheduledAt->format('Y-m-d\TH:i');
                $this->cutPublishAuto[$uuid] = true;
            }

            $previousEffectiveAt = $currentScheduledAt;
            $previousGapHours = $currentGapHours;
        }
    }

    private function parseLocalDateTime(?string $value): ?CarbonImmutable
    {
        $raw = mb_trim((string) $value);
        if ($raw === '') {
            return null;
        }

        try {
            // Date::use(CarbonImmutable) está ativo (AppServiceProvider), então o
            // factory devolve CarbonImmutable — o instanceof antigo contra o Carbon
            // mutável nunca casava e descartava a data digitada pelo usuário.
            return Date::createFromFormat('Y-m-d\TH:i', $raw);
        } catch (Throwable) {
            return null;
        }
    }

    private function gapHoursForCut(string $uuid): int
    {
        $gap = $this->cutScheduleGapHours[$uuid] ?? 2;

        return in_array($gap, [1, 2, 3, 4, 5, 6], true) ? $gap : 2;
    }
}
