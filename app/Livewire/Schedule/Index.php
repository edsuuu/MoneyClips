<?php

declare(strict_types=1);

namespace App\Livewire\Schedule;

use App\Livewire\Concerns\WithToasts;
use App\Models\User;
use App\Models\YoutubeShort;
use App\Services\AutoPost\AutoPostDispatcher;
use App\Services\AutoPost\WindowSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Livewire\Component;
use Throwable;

/**
 * /agenda — visão semanal dos slots com horários por dia da semana (mapa em
 * users.auto_post_schedule, fuso SP) e o status de cada disparo: postado,
 * próximo, futuro ou pulado. Lê as flags auto_post_*_enabled do usuário
 * autenticado e permite editar os horários (inputs de hora) sem deploy.
 */
final class Index extends Component
{
    use WithToasts;

    /** Rótulos dos dias ISO (1=Seg..7=Dom) — ordem de exibição do editor. */
    public const array WEEKDAY_LABELS = [1 => 'Seg', 2 => 'Ter', 3 => 'Qua', 4 => 'Qui', 5 => 'Sex', 6 => 'Sáb', 7 => 'Dom'];

    private const string TIMEZONE = WindowSchedule::TIMEZONE;

    /** Tolerância (minutos) pra casar dispatched_at com o minuto sorteado. */
    private const int MATCH_TOLERANCE_MIN = 3;

    /**
     * Horários editáveis por dia da semana (ISO 1=Seg..7=Dom), cada um "HH:MM"
     * ligado a um input type=time na UI. Valores são mixed de propósito:
     * propriedade pública do Livewire é fronteira de confiança (o payload vem
     * do cliente) — o saneamento acontece no saveSchedule.
     *
     * @var array<int, list<mixed>>
     */
    public array $scheduleTimes = [];

    public function mount(): void
    {
        // Prefill com a agenda vigente: a configurada no banco, ou os horários
        // computados do fallback pra cada dia desta semana.
        $monday = Date::now(self::TIMEZONE)->startOfWeek(CarbonImmutable::MONDAY);
        for ($i = 0; $i < 7; $i++) {
            $day = $monday->copy()->addDays($i);
            $this->scheduleTimes[$day->dayOfWeekIso] = WindowSchedule::timesFor($day);
        }
    }

    /** Adiciona um horário vazio no dia — o usuário ajusta no input de hora. */
    public function addTime(int $dayOfWeek): void
    {
        if ($dayOfWeek < 1 || $dayOfWeek > 7) {
            return;
        }

        $this->scheduleTimes[$dayOfWeek][] = '12:00';
    }

    public function removeTime(int $dayOfWeek, int $index): void
    {
        $times = $this->scheduleTimes[$dayOfWeek] ?? [];
        if (! array_key_exists($index, $times)) {
            return;
        }

        array_splice($times, $index, 1);
        $this->scheduleTimes[$dayOfWeek] = $times;
    }

    /**
     * Salva a agenda semanal no banco (users.auto_post_schedule): mapa dia
     * ISO => horários "HH:MM" deduplicados e ordenados. Dia sem horários =
     * sem postagens naquele dia. Vale no próximo tick do scheduler, sem deploy.
     */
    public function saveSchedule(): void
    {
        $user = $this->currentUser();
        if (! $user instanceof User) {
            return;
        }

        $schedule = [];
        foreach (array_keys(self::WEEKDAY_LABELS) as $dayOfWeek) {
            $times = [];
            foreach ($this->scheduleTimes[$dayOfWeek] ?? [] as $time) {
                if (! is_string($time) || mb_trim($time) === '') {
                    continue; // input limpo pelo usuário — ignora
                }

                // Inputs type=time enviam "HH:MM" (ou "HH:MM:SS" conforme o navegador).
                if (preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', $time) !== 1) {
                    $this->toast(sprintf('Horário inválido em %s: "%s".', self::WEEKDAY_LABELS[$dayOfWeek], $time), 'danger');

                    return;
                }

                $times[] = mb_substr($time, 0, 5);
            }

            $times = array_values(array_unique($times));
            sort($times);
            $schedule[$dayOfWeek] = $times;
        }

        $user->auto_post_schedule = $schedule;
        $user->save();

        $this->scheduleTimes = $schedule;
        $this->toast('Agenda semanal salva.');
    }

    /**
     * Dispara o AutoPostDispatcher imediatamente, ignorando o lock da janela
     * atual. Aceita só $slotDate=hoje (SP) — repostar slot de dia passado não
     * faz sentido (já era pra ter postado naquele momento). Sorteia o próximo
     * Short do estoque e publica nas plataformas habilitadas.
     */
    public function forceDispatch(string $slotDate): void
    {
        try {
            Log::info('[Schedule] forceDispatch chamado.', ['slotDate' => $slotDate]);
            $now = Date::now(self::TIMEZONE);
            if ($slotDate !== $now->format('Y-m-d')) {
                $this->toast('Só dá pra forçar disparo de slots do dia atual.', 'danger');

                return;
            }

            Log::info('[Schedule] Limpando cache da janela.');
            $key = WindowSchedule::windowKey();
            if ($key !== null) {
                Cache::forget($key);
            }

            Log::info('[Schedule] Chamando AutoPostDispatcher com force=true.');
            resolve(AutoPostDispatcher::class)->run(1, force: true);
            Log::info('[Schedule] AutoPostDispatcher terminou.');
            $this->toast('Disparo forçado enviado para o estoque.');
        } catch (Throwable $throwable) {
            Log::error('[Schedule] Falha ao forçar disparo.', ['error' => $throwable->getMessage()]);
            $this->toast('Falha ao forçar disparo: '.$throwable->getMessage(), 'danger');
        }
    }

    /** Liga/desliga YouTube na auto-postagem do usuário atual. */
    public function toggleYoutube(): void
    {
        $user = $this->currentUser();
        if (! $user instanceof User) {
            return;
        }

        $user->auto_post_youtube_enabled = ! $user->auto_post_youtube_enabled;
        $user->save();
        $this->toast(sprintf('YouTube %s.', $user->auto_post_youtube_enabled ? 'ativado' : 'pausado'));
    }

    /** Liga/desliga TikTok na auto-postagem do usuário atual. */
    public function toggleTiktok(): void
    {
        $user = $this->currentUser();
        if (! $user instanceof User) {
            return;
        }

        $user->auto_post_tiktok_enabled = ! $user->auto_post_tiktok_enabled;
        $user->save();
        $this->toast(sprintf('TikTok %s.', $user->auto_post_tiktok_enabled ? 'ativado' : 'pausado'));
    }

    public function render(): View
    {
        $now = Date::now(self::TIMEZONE);
        $monday = $now->copy()->startOfWeek(CarbonImmutable::MONDAY);
        $sunday = $monday->copy()->endOfWeek(CarbonImmutable::SUNDAY);

        // Carrega todos os shorts da semana de uma vez (1 query).
        $shorts = YoutubeShort::query()
            ->whereNotNull('dispatched_at')
            ->whereBetween('dispatched_at', [$monday->copy()->startOfDay(), $sunday->copy()->endOfDay()])
            ->oldest('dispatched_at')
            ->get(['id', 'youtube_id', 'title', 'dispatched_at', 'posted_youtube_at', 'posted_tiktok_at', 'youtube_video_id']);

        $days = [];
        for ($i = 0; $i < 7; $i++) {
            $day = $monday->copy()->addDays($i);
            $days[] = $this->buildDay($day, $now, $shorts);
        }

        return view('livewire.schedule.index', [
            'days' => $days,
            'now' => $now,
            'weekStart' => $monday,
            'weekEnd' => $sunday,
            'user' => $this->currentUser(),
            // Dias podem ter quantidades diferentes de slots — a grade usa o maior.
            'maxSlots' => max(1, ...array_map(static fn (array $day): int => count($day['slots']), $days)),
            'nextSlot' => $this->findNextSlot($days, $now),
        ]);
    }

    /**
     * @param  iterable<int, YoutubeShort>  $allShorts
     * @return array{date: CarbonImmutable, label: string, isToday: bool, slots: list<array<string, mixed>>}
     */
    private function buildDay(CarbonImmutable $day, CarbonImmutable $now, iterable $allShorts): array
    {
        $isToday = $day->isSameDay($now);
        $slots = [];

        foreach (WindowSchedule::timesFor($day) as $time) {
            $slotTime = $day->setTimeFromTimeString($time);
            $short = $this->findShortForSlot($slotTime, $allShorts);

            $status = match (true) {
                $short instanceof YoutubeShort => 'posted',
                $slotTime->isFuture() => 'future',
                default => 'skipped',
            };

            $slots[] = [
                'time_label' => $time,
                'status' => $status,
                'short' => $short,
                'datetime' => $slotTime,
                'is_next' => false, // marcado depois
            ];
        }

        return [
            'date' => $day,
            'label' => $this->dayLabel($day),
            'isToday' => $isToday,
            'slots' => $slots,
        ];
    }

    /**
     * @param  iterable<int, YoutubeShort>  $allShorts
     */
    private function findShortForSlot(CarbonImmutable $slotTime, iterable $allShorts): ?YoutubeShort
    {
        foreach ($allShorts as $short) {
            $dispatched = $short->dispatched_at;
            if ($dispatched === null) {
                continue;
            }

            $dispatchedSp = $dispatched->copy()->timezone(self::TIMEZONE);
            if (abs($dispatchedSp->diffInMinutes($slotTime, true)) <= self::MATCH_TOLERANCE_MIN) {
                return $short;
            }
        }

        return null;
    }

    /**
     * @param  list<array{date: CarbonImmutable, label: string, isToday: bool, slots: list<array<string, mixed>>}>  $days
     * @return array{time_label: string, day_label: string, datetime: CarbonImmutable, in: string}|null
     */
    private function findNextSlot(array &$days, CarbonImmutable $now): ?array
    {
        foreach ($days as $dayIndex => $day) {
            foreach ($day['slots'] as $slotIndex => $slot) {
                if ($slot['status'] === 'future') {
                    $days[$dayIndex]['slots'][$slotIndex]['is_next'] = true;

                    /** @var CarbonImmutable $dt */
                    $dt = $slot['datetime'];
                    $diffMin = (int) round($now->diffInMinutes($dt, true));

                    return [
                        'time_label' => (string) $slot['time_label'],
                        'day_label' => $day['label'],
                        'datetime' => $dt,
                        'in' => $this->humanDiff($diffMin),
                    ];
                }
            }
        }

        return null;
    }

    private function currentUser(): ?User
    {
        $user = Auth::user();

        return $user instanceof User ? $user : null;
    }

    private function dayLabel(CarbonImmutable $day): string
    {
        return self::WEEKDAY_LABELS[$day->dayOfWeekIso] ?? 'Dom';
    }

    private function humanDiff(int $minutes): string
    {
        if ($minutes < 60) {
            return sprintf('em %d min', $minutes);
        }

        $hours = intdiv($minutes, 60);
        $rem = $minutes % 60;

        return $rem === 0 ? sprintf('em %dh', $hours) : sprintf('em %dh %dmin', $hours, $rem);
    }
}
