<?php

declare(strict_types=1);

namespace App\Livewire\Schedule;

use App\Helpers\Hashtags;
use App\Livewire\Concerns\WithToasts;
use App\Models\ScheduleSlot;
use App\Models\YoutubeShort;
use App\Services\AutoPost\AutoPostDispatcherService;
use App\Services\AutoPost\SlotStatusService;
use App\Services\AutoPost\WeekGeneratorService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Livewire\Component;
use Throwable;

final class Index extends Component
{
    use WithToasts;

    public const array WEEKDAY_LABELS = [1 => 'Seg', 2 => 'Ter', 3 => 'Qua', 4 => 'Qui', 5 => 'Sex', 6 => 'Sáb', 7 => 'Dom'];

    private const array MONTH_LABELS = [1 => 'JANEIRO', 2 => 'FEVEREIRO', 3 => 'MARÇO', 4 => 'ABRIL', 5 => 'MAIO', 6 => 'JUNHO', 7 => 'JULHO', 8 => 'AGOSTO', 9 => 'SETEMBRO', 10 => 'OUTUBRO', 11 => 'NOVEMBRO', 12 => 'DEZEMBRO'];

    private const array WEEK_OFFSETS = [-1, 0, 1, 2];

    public int $weekOffset = 0;

    public string $view = 'week';

    public int $videosPerDay = 3;

    /**
     * Rascunho dos slots EDITÁVEIS (não despachados) da semana visível,
     * chave = data "Y-m-d". Fronteira de confiança: payload vem do cliente,
     * saneamento no save().
     *
     * @var array<string, list<array{id: int|null, time: mixed, short_id: int|null, active: bool}>>
     */
    public array $days = [];

    /** @var list<int> */
    public array $removedIds = [];

    public bool $dirty = false;

    /**
     * Modal de atribuição de vídeo.
     *
     * @var array{date: string, dateLabel: string, index: int, time: mixed, short_id: int|null, title: mixed, hashtags: mixed, browse: bool, query: mixed}|null
     */
    public ?array $picker = null;

    public function mount(): void
    {
        $this->loadWeek();
    }

    public function selectWeek(int $offset): void
    {
        if (! in_array($offset, self::WEEK_OFFSETS, true)) {
            return;
        }

        $this->weekOffset = $offset;
        $this->loadWeek();
    }

    public function setView(string $view): void
    {
        $this->view = in_array($view, ['week', 'month'], true) ? $view : 'week';
    }

    public function updated(string $property): void
    {
        if (str_starts_with($property, 'days.')) {
            $this->dirty = true;
        }
    }

    public function addSlot(string $date): void
    {
        if (! $this->editableDate($date)) {
            return;
        }

        $dispatched = $this->dispatchedCountFor($date);
        if (count($this->days[$date] ?? []) + $dispatched >= ScheduleSlot::MAX_PER_DAY) {
            $this->toast(sprintf('Máx. %d horários por dia.', ScheduleSlot::MAX_PER_DAY), 'danger');

            return;
        }

        $this->days[$date][] = ['id' => null, 'time' => '12:00', 'short_id' => null, 'active' => true];
        $this->dirty = true;
    }

    public function removeSlot(string $date, int $index): void
    {
        $slot = $this->days[$date][$index] ?? null;
        if ($slot === null) {
            return;
        }

        if (is_int($slot['id'])) {
            $this->removedIds[] = $slot['id'];
        }

        array_splice($this->days[$date], $index, 1);
        $this->dirty = true;
    }

    public function toggleSlot(string $date, int $index): void
    {
        if (! isset($this->days[$date][$index])) {
            return;
        }

        $this->days[$date][$index]['active'] = ! $this->days[$date][$index]['active'];
        $this->dirty = true;
    }

    public function swapVideos(string $fromDate, int $fromIndex, string $toDate, int $toIndex): void
    {
        if (! isset($this->days[$fromDate][$fromIndex], $this->days[$toDate][$toIndex])) {
            return;
        }

        $from = $this->days[$fromDate][$fromIndex]['short_id'];
        $this->days[$fromDate][$fromIndex]['short_id'] = $this->days[$toDate][$toIndex]['short_id'];
        $this->days[$toDate][$toIndex]['short_id'] = $from;
        $this->dirty = true;
    }

    public function openPicker(string $date, int $index): void
    {
        $slot = $this->days[$date][$index] ?? null;
        if ($slot === null) {
            return;
        }

        $short = is_int($slot['short_id']) ? YoutubeShort::query()->find($slot['short_id']) : null;

        $this->picker = [
            'date' => $date,
            'dateLabel' => CarbonImmutable::parse($date)->format('d/m'),
            'index' => $index,
            'time' => is_string($slot['time']) ? $slot['time'] : '',
            'short_id' => $short?->id,
            'title' => $short->title ?? '',
            'hashtags' => Hashtags::toInput($short->hashtags ?? null),
            'browse' => $short === null,
            'query' => '',
        ];
    }

    public function closePicker(): void
    {
        $this->picker = null;
    }

    public function pickerBrowse(): void
    {
        if ($this->picker !== null) {
            $this->picker['browse'] = true;
        }
    }

    public function pickerSelect(int $shortId): void
    {
        if ($this->picker === null) {
            return;
        }

        $short = YoutubeShort::query()->find($shortId);
        if (! $short instanceof YoutubeShort) {
            return;
        }

        $this->picker['short_id'] = $short->id;
        $this->picker['title'] = $short->title ?? '';
        $this->picker['hashtags'] = Hashtags::toInput($short->hashtags);
        $this->picker['browse'] = false;
    }

    public function savePicker(): void
    {
        $picker = $this->picker;
        if ($picker === null || ! isset($this->days[$picker['date']][$picker['index']])) {
            return;
        }

        $time = is_string($picker['time']) ? mb_substr(mb_trim($picker['time']), 0, 5) : '';
        if (preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time) !== 1) {
            $this->toast(sprintf('Horário inválido: "%s".', $time), 'danger');

            return;
        }

        if (is_int($picker['short_id'])) {
            $short = YoutubeShort::query()->find($picker['short_id']);
            if ($short instanceof YoutubeShort) {
                $title = is_string($picker['title']) ? mb_trim($picker['title']) : '';
                $short->title = $title === '' ? $short->title : $title;
                $short->hashtags = Hashtags::parse(is_string($picker['hashtags']) ? $picker['hashtags'] : '');
                $short->save();
            }
        }

        $this->days[$picker['date']][$picker['index']]['time'] = $time;
        $this->days[$picker['date']][$picker['index']]['short_id'] = $picker['short_id'];
        $this->dirty = true;
        $this->picker = null;
        $this->toast('Slot atualizado — salve a agenda para aplicar.');
    }

    public function save(): void
    {
        $monday = $this->monday();
        $clean = [];

        foreach ($this->days as $date => $slots) {
            $times = $this->dispatchedTimesFor($date);

            foreach ($slots as $slot) {
                $time = is_string($slot['time']) ? mb_substr(mb_trim($slot['time']), 0, 5) : '';
                if (preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time) !== 1) {
                    $this->toast(sprintf('Horário inválido em %s: "%s".', $date, (string) $slot['time']), 'danger');

                    return;
                }

                if (in_array($time, $times, true)) {
                    $this->toast(sprintf('Horário duplicado em %s: %s.', $date, $time), 'danger');

                    return;
                }

                $times[] = $time;
                $clean[$date][] = [
                    'id' => is_int($slot['id']) ? $slot['id'] : null,
                    'time' => $time,
                    'short_id' => is_int($slot['short_id']) ? $slot['short_id'] : null,
                    'active' => (bool) $slot['active'],
                ];
            }

            if (count($times) > ScheduleSlot::MAX_PER_DAY) {
                $this->toast(sprintf('%s passou de %d horários.', $date, ScheduleSlot::MAX_PER_DAY), 'danger');

                return;
            }
        }

        DB::transaction(function () use ($clean): void {
            if ($this->removedIds !== []) {
                ScheduleSlot::query()
                    ->whereIn('id', $this->removedIds)
                    ->whereNull('dispatched_at')
                    ->delete();
            }

            foreach ($clean as $date => $slots) {
                foreach ($slots as $slot) {
                    $attributes = [
                        'slot_date' => $date,
                        'slot_time' => $slot['time'].':00',
                        'youtube_short_id' => $slot['short_id'],
                        'is_active' => $slot['active'],
                    ];

                    if ($slot['id'] !== null) {
                        ScheduleSlot::query()->whereKey($slot['id'])->whereNull('dispatched_at')->update($attributes);
                    } else {
                        ScheduleSlot::query()->create($attributes);
                    }
                }
            }
        });

        $this->loadWeek();
        $this->toast(sprintf('Agenda da semana de %s salva.', $monday->format('d/m')));
    }

    public function generateWeek(): void
    {
        if ($this->isLockedWeek()) {
            return;
        }

        $created = resolve(WeekGeneratorService::class)->generate($this->monday(), max(0, $this->videosPerDay));

        $this->loadWeek();
        $this->toast($created > 0
            ? sprintf('%d slots gerados. Revise e salve — nada é publicado sem confirmação.', $created)
            : 'Nenhum slot novo — semana já preenchida ou sem horários no banco pra copiar (adicione com "+ Horário").');
    }

    public function incPerDay(): void
    {
        $this->videosPerDay = min(ScheduleSlot::MAX_PER_DAY, $this->videosPerDay + 1);
    }

    public function decPerDay(): void
    {
        $this->videosPerDay = max(0, $this->videosPerDay - 1);
    }

    public function forceDispatch(int $slotId): void
    {
        try {
            $slot = ScheduleSlot::query()->find($slotId);
            if (! $slot instanceof ScheduleSlot || $slot->dispatched_at !== null || $slot->youtube_short_id === null) {
                $this->toast('Slot não está apto ao disparo manual.', 'danger');

                return;
            }

            $dispatched = resolve(AutoPostDispatcherService::class)->dispatchSlot($slot);
            $this->loadWeek();
            $this->toast($dispatched
                ? 'Disparo enviado — acompanhe o resultado no slot.'
                : 'Slot já reivindicado por outro disparo.');
        } catch (Throwable $throwable) {
            $this->toast('Falha ao forçar disparo: '.$throwable->getMessage(), 'danger');
        }
    }

    public function toggleRandomMode(): void
    {
        $enabled = ! $this->randomModeEnabled();
        Cache::forever(AutoPostDispatcherService::RANDOM_MODE, $enabled);

        $this->toast($enabled
            ? 'Modo aleatório ativado — slots vazios no horário recebem um vídeo pronto sorteado (reencode + post).'
            : 'Modo aleatório desativado — slot sem vídeo atribuído fica pulado.');
    }

    private function randomModeEnabled(): bool
    {
        return (bool) Cache::get(AutoPostDispatcherService::RANDOM_MODE, false);
    }

    private function monday(): CarbonImmutable
    {
        return Date::now()->startOfWeek(CarbonImmutable::MONDAY)->addWeeks($this->weekOffset)->startOfDay();
    }

    private function isLockedWeek(): bool
    {
        return $this->monday()->addDays(6)->endOfDay()->isPast();
    }

    private function editableDate(string $date): bool
    {
        return array_key_exists($date, $this->days);
    }

    private function loadWeek(): void
    {
        $this->days = [];
        $this->removedIds = [];
        $this->dirty = false;

        $monday = $this->monday();

        for ($i = 0; $i < 7; $i++) {
            $this->days[$monday->addDays($i)->toDateString()] = [];
        }

        if ($this->isLockedWeek()) {
            return;
        }

        $slots = ScheduleSlot::query()
            ->forWeek($monday)
            ->whereNull('dispatched_at')
            ->orderBy('slot_time')
            ->get();

        foreach ($slots as $slot) {
            $this->days[$slot->slot_date->toDateString()][] = [
                'id' => $slot->id,
                'time' => $slot->timeLabel(),
                'short_id' => $slot->youtube_short_id,
                'active' => $slot->is_active,
            ];
        }
    }

    /**
     * Classes Tailwind por status do slot — mapa único de apresentação,
     * consumido via @class na blade (o design pinta caixa/hora/dot juntos).
     *
     * @return array{container: string, time: string, dot: string}
     */
    private function slotPresentation(string $status): array
    {
        return match ($status) {
            'paused' => ['container' => 'border-[1.5px] border-dashed border-slate-700 opacity-45', 'time' => 'text-slate-500', 'dot' => 'border-[1.5px] border-slate-600'],
            'posted' => ['container' => 'border border-slate-800 bg-slate-900/70 opacity-60', 'time' => 'text-slate-500', 'dot' => 'bg-emerald-400'],
            'posting' => ['container' => 'border border-sky-500/40 bg-sky-950/20', 'time' => 'text-slate-300', 'dot' => 'bg-sky-400'],
            'failed' => ['container' => 'border border-red-500/50 bg-red-950/20', 'time' => 'text-red-400', 'dot' => 'bg-red-500'],
            'partial' => ['container' => 'border border-amber-500/60 bg-amber-950/15', 'time' => 'text-amber-300', 'dot' => 'bg-amber-400'],
            'due' => ['container' => 'border border-slate-600 bg-slate-800/60', 'time' => 'text-slate-300', 'dot' => 'bg-sky-400'],
            'next' => ['container' => 'border-[1.5px] border-emerald-500/60 bg-emerald-950/20', 'time' => 'text-emerald-300', 'dot' => 'bg-emerald-400'],
            'skipped' => ['container' => 'border-[1.5px] border-amber-500/50 bg-amber-950/10', 'time' => 'text-amber-300', 'dot' => 'bg-amber-400'],
            'future' => ['container' => 'border border-slate-700 bg-slate-900', 'time' => 'text-slate-300', 'dot' => 'border-[1.5px] border-slate-600'],
            default => ['container' => 'border-[1.5px] border-dashed border-slate-700', 'time' => 'text-slate-500', 'dot' => 'border-[1.5px] border-slate-600'],
        };
    }

    /**
     * View model de um slot (linha do kanban), com as classes prontas.
     *
     * @param  list<array{platform: string, name: string, ok: bool, pending: bool, reason: string|null}>  $platforms
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function slotEntry(string $status, array $platforms, array $attributes): array
    {
        $presentation = $this->slotPresentation($status);

        return [
            ...$attributes,
            'status' => $status,
            'platforms' => $platforms,
            'is_next' => false,
            'containerClass' => $presentation['container'],
            'timeClass' => $presentation['time'],
            'dotClass' => $presentation['dot'],
            'hasFailures' => array_any($platforms, static fn (array $p): bool => ! $p['ok'] && ! $p['pending']),
        ];
    }

    /**
     * @param  Collection<int, ScheduleSlot>  $daySlots  Slots do DIA vindos do banco.
     * @param  array<int, string>  $draftShortTitles
     * @return array{date: CarbonImmutable, dateString: string, name: string, isToday: bool, postedCount: int, total: int, canAdd: bool, showMaxNotice: bool, slots: list<array<string, mixed>>}
     */
    private function buildDay(CarbonImmutable $day, CarbonImmutable $now, Collection $daySlots, array $draftShortTitles, bool $locked): array
    {
        $date = $day->toDateString();
        $entries = [];

        foreach ($daySlots as $slot) {
            if ($slot->dispatched_at === null && ! $locked) {
                continue;
            }

            $resolved = SlotStatusService::resolve($slot, $now);
            $entries[] = $this->slotEntry($resolved['status'], $resolved['platforms'], [
                'editable' => false,
                'id' => $slot->id,
                'index' => null,
                'time' => $slot->timeLabel(),
                'title' => $slot->youtubeShort->title ?? $slot->youtubeShort->youtube_id ?? null,
                'active' => $slot->is_active,
            ]);
        }

        foreach ($this->days[$date] ?? [] as $index => $draft) {
            $transient = new ScheduleSlot([
                'slot_date' => $date,
                'slot_time' => (is_string($draft['time']) ? $draft['time'] : '00:00').':00',
                'youtube_short_id' => $draft['short_id'],
                'is_active' => $draft['active'],
            ]);

            $resolved = SlotStatusService::resolve($transient, $now);
            $entries[] = $this->slotEntry($resolved['status'], [], [
                'editable' => true,
                'id' => $draft['id'],
                'index' => $index,
                'time' => is_string($draft['time']) ? $draft['time'] : '',
                'title' => is_int($draft['short_id']) ? ($draftShortTitles[$draft['short_id']] ?? null) : null,
                'active' => $draft['active'],
            ]);
        }

        usort($entries, static fn (array $a, array $b): int => strcmp((string) $a['time'], (string) $b['time']));

        $posted = count(array_filter($entries, static fn (array $e): bool => in_array($e['status'], ['posted', 'partial'], true)));

        return [
            'date' => $day,
            'dateString' => $date,
            'name' => self::WEEKDAY_LABELS[$day->dayOfWeekIso],
            'isToday' => $day->isSameDay($now),
            'postedCount' => $posted,
            'total' => count($entries),
            'canAdd' => ! $locked && count($entries) < ScheduleSlot::MAX_PER_DAY,
            'showMaxNotice' => ! $locked && count($entries) >= ScheduleSlot::MAX_PER_DAY,
            'slots' => $entries,
        ];
    }

    /**
     * Marca o primeiro slot futuro da semana corrente como "próximo".
     *
     * @param  list<array<string, mixed>>  $boardDays
     */
    private function markNextSlot(array &$boardDays): void
    {
        foreach ($boardDays as $dayIndex => $day) {
            /** @var list<array<string, mixed>> $daySlots */
            $daySlots = $day['slots'];
            foreach ($daySlots as $slotIndex => $slot) {
                if ($slot['status'] === 'future') {
                    $presentation = $this->slotPresentation('next');
                    $daySlots[$slotIndex] = [
                        ...$slot,
                        'is_next' => true,
                        'status' => 'next',
                        'containerClass' => $presentation['container'],
                        'timeClass' => $presentation['time'],
                        'dotClass' => $presentation['dot'],
                    ];
                    $boardDays[$dayIndex]['slots'] = $daySlots;

                    return;
                }
            }
        }
    }

    /** @return array<int, string> */
    private function draftShortTitles(): array
    {
        $ids = [];
        foreach ($this->days as $slots) {
            foreach ($slots as $slot) {
                if (is_int($slot['short_id'])) {
                    $ids[] = $slot['short_id'];
                }
            }
        }

        if ($ids === []) {
            return [];
        }

        return YoutubeShort::query()
            ->whereIn('id', $ids)
            ->get(['id', 'title', 'youtube_id'])
            ->mapWithKeys(fn (YoutubeShort $s): array => [$s->id => $s->title ?? $s->youtube_id])
            ->all();
    }

    private function dispatchedCountFor(string $date): int
    {
        return ScheduleSlot::query()
            ->where('slot_date', $date)
            ->whereNotNull('dispatched_at')
            ->count();
    }

    /** @return list<string> */
    private function dispatchedTimesFor(string $date): array
    {
        $times = ScheduleSlot::query()
            ->where('slot_date', $date)
            ->whereNotNull('dispatched_at')
            ->pluck('slot_time')
            ->all();

        return array_values(array_map(static fn ($time): string => mb_substr((string) $time, 0, 5), $times));
    }

    /** @return list<array{offset: int, label: string, rangeLabel: string, current: bool, selected: bool}> */
    private function weekTabs(CarbonImmutable $now): array
    {
        $tabs = [];
        foreach (self::WEEK_OFFSETS as $offset) {
            $monday = $now->startOfWeek(CarbonImmutable::MONDAY)->addWeeks($offset);
            $locked = $monday->addDays(6)->endOfDay()->isPast();
            $tabs[] = [
                'offset' => $offset,
                'label' => $offset === 0 ? 'Semana atual' : 'Semana de '.$monday->format('d/m'),
                'rangeLabel' => $monday->format('d/m').' – '.$monday->addDays(6)->format('d/m').($locked ? ' · bloqueada' : ''),
                'current' => $offset === 0,
                'selected' => $offset === $this->weekOffset,
            ];
        }

        return $tabs;
    }

    /** @return array{time: string, label: string, in: string}|null */
    private function nextDispatch(CarbonImmutable $now): ?array
    {
        $slot = ScheduleSlot::query()
            ->pending($now)
            ->where('is_active', true)
            ->whereNotNull('youtube_short_id')
            ->orderBy('slot_date')
            ->orderBy('slot_time')
            ->first();

        if (! $slot instanceof ScheduleSlot) {
            return null;
        }

        $minutes = (int) round($now->diffInMinutes($slot->scheduledAt(), true));

        return [
            'time' => $slot->timeLabel(),
            'label' => $slot->slot_date->format('d/m').' '.$slot->timeLabel(),
            'in' => $this->humanDiff($minutes),
        ];
    }

    /** @return array<int, array{id: int, title: string, tags: string, selected: bool}> */
    private function pickerVideos(): array
    {
        if ($this->picker === null || ! $this->picker['browse']) {
            return [];
        }

        $query = is_string($this->picker['query']) ? mb_trim($this->picker['query']) : '';
        $selectedId = $this->picker['short_id'];

        return YoutubeShort::query()
            ->readyToSchedule()
            ->when($query !== '', fn ($q) => $q->where('title', 'like', '%'.$query.'%'))
            ->orderBy('ready_at')
            ->limit(24)
            ->get(['id', 'title', 'youtube_id', 'hashtags'])
            ->map(fn (YoutubeShort $s): array => [
                'id' => $s->id,
                'title' => $s->title ?? $s->youtube_id,
                'tags' => Hashtags::toInput($s->hashtags),
                'selected' => $selectedId === $s->id,
            ])->values()->all();
    }

    private function monthDotClass(string $status): string
    {
        return match (true) {
            in_array($status, ['posted', 'partial'], true) => 'bg-emerald-400',
            $status === 'failed' => 'bg-red-500',
            $status === 'skipped' => 'bg-amber-400',
            in_array($status, ['posting', 'next', 'due'], true) => 'bg-sky-400',
            default => 'bg-slate-500',
        };
    }

    /**
     * Grade do mês da semana visível (visão "Mês", read-only).
     *
     * @return array{label: string, cells: list<array{real: bool, date: string, dateLabel: string, dnum: int, isToday: bool, previewSlots: array<int, array{time: string, dotClass: string}>, extraCount: int, slots: array<int, array{time: string, title: string|null, status: string}>}>}
     */
    private function monthData(CarbonImmutable $monday, CarbonImmutable $now): array
    {
        $monthStart = $monday->startOfMonth();
        $monthEnd = $monday->endOfMonth();

        $slots = ScheduleSlot::query()
            ->with(['youtubeShort', 'socialPosts'])
            ->whereBetween('slot_date', [$monthStart->toDateString(), $monthEnd->toDateString()])
            ->orderBy('slot_time')
            ->get()
            ->groupBy(fn (ScheduleSlot $slot): string => $slot->slot_date->toDateString());

        $cells = [];
        $cursor = $monthStart->startOfWeek(CarbonImmutable::MONDAY);
        $gridEnd = $monthEnd->endOfWeek(CarbonImmutable::SUNDAY);

        while ($cursor->lessThanOrEqualTo($gridEnd)) {
            $inMonth = $cursor->month === $monthStart->month;
            $daySlots = $inMonth
                ? ($slots[$cursor->toDateString()] ?? new EloquentCollection)
                    ->map(fn (ScheduleSlot $slot): array => [
                        'time' => $slot->timeLabel(),
                        'title' => $slot->youtubeShort?->title,
                        'status' => SlotStatusService::resolve($slot, $now)['status'],
                    ])->values()->all()
                : [];

            $cells[] = [
                'real' => $inMonth,
                'date' => $cursor->toDateString(),
                'dateLabel' => $cursor->format('d/m'),
                'dnum' => $cursor->day,
                'isToday' => $cursor->isSameDay($now),
                'previewSlots' => array_map(fn (array $slot): array => [
                    'time' => $slot['time'],
                    'dotClass' => $this->monthDotClass($slot['status']),
                ], array_slice($daySlots, 0, 3)),
                'extraCount' => max(0, count($daySlots) - 3),
                'slots' => $daySlots,
            ];
            $cursor = $cursor->addDay();
        }

        return [
            'label' => self::MONTH_LABELS[$monthStart->month].' '.$monthStart->year,
            'cells' => $cells,
        ];
    }

    private function humanDiff(int $minutes): string
    {
        if ($minutes < 60) {
            return sprintf('em %d min', $minutes);
        }

        $hours = intdiv($minutes, 60);
        $rem = $minutes % 60;
        if ($hours < 24) {
            return $rem === 0 ? sprintf('em %dh', $hours) : sprintf('em %dh %dmin', $hours, $rem);
        }

        return sprintf('em %dd %dh', intdiv($hours, 24), $hours % 24);
    }

    public function render(): View
    {
        $now = Date::now();
        $monday = $this->monday();
        $locked = $this->isLockedWeek();

        $slotsByDate = ScheduleSlot::query()
            ->with(['youtubeShort', 'socialPosts'])
            ->forWeek($monday)
            ->orderBy('slot_time')
            ->get()
            ->groupBy(fn (ScheduleSlot $slot): string => $slot->slot_date->toDateString());

        $draftShortTitles = $this->draftShortTitles();
        $boardDays = [];
        for ($i = 0; $i < 7; $i++) {
            $day = $monday->addDays($i);
            $daySlots = $slotsByDate->get($day->toDateString()) ?? new EloquentCollection;
            $boardDays[] = $this->buildDay($day, $now, $daySlots, $draftShortTitles, $locked);
        }

        if ($this->weekOffset === 0) {
            $this->markNextSlot($boardDays);
        }

        return view('livewire.schedule.index', [
            'boardDays' => $boardDays,
            'weekTabs' => $this->weekTabs($now),
            'locked' => $locked,
            'weekRangeLabel' => $monday->format('d/m').' – '.$monday->addDays(6)->format('d/m'),
            'weekdayLabels' => self::WEEKDAY_LABELS,
            'maxPerDay' => ScheduleSlot::MAX_PER_DAY,
            'nextDispatch' => $this->nextDispatch($now),
            'randomMode' => $this->randomModeEnabled(),
            'pickerVideos' => $this->pickerVideos(),
            'monthData' => $this->view === 'month' ? $this->monthData($monday, $now) : null,
        ]);
    }
}
