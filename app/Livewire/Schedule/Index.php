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
 * /agenda — visão semanal dos slots do dia (horas em users.auto_post_slot_hours,
 * fuso SP) com o minuto sorteado por dia e o status de cada disparo: postado,
 * próximo, futuro ou pulado. Lê as flags auto_post_*_enabled do usuário
 * autenticado e permite editar as horas dos slots sem deploy.
 */
final class Index extends Component
{
    use WithToasts;

    private const string TIMEZONE = WindowSchedule::TIMEZONE;

    /** Tolerância (minutos) pra casar dispatched_at com o minuto sorteado. */
    private const int MATCH_TOLERANCE_MIN = 3;

    /** Horas dos slots editáveis pela UI ("9, 12, 15, 18, 21"). */
    public string $slotHoursInput = '';

    public function mount(): void
    {
        $this->slotHoursInput = implode(', ', WindowSchedule::slotHours());
    }

    /**
     * Salva as horas dos slots no banco (users.auto_post_slot_hours). Entrada
     * livre "9, 12, 15" — valida 0–23, deduplica e ordena. A grade e o
     * scheduler passam a usar na hora (próximo tick), sem deploy.
     */
    public function saveSlotHours(): void
    {
        $user = $this->currentUser();
        if (! $user instanceof User) {
            return;
        }

        $hours = [];
        foreach (explode(',', $this->slotHoursInput) as $token) {
            $token = mb_trim($token);
            if ($token === '') {
                continue;
            }

            if (! ctype_digit($token) || (int) $token > 23) {
                $this->toast(sprintf('Hora inválida: "%s". Use números de 0 a 23 separados por vírgula.', $token), 'danger');

                return;
            }

            $hours[] = (int) $token;
        }

        $hours = array_values(array_unique($hours));
        sort($hours);

        if ($hours === []) {
            $this->toast('Informe pelo menos uma hora (0–23).', 'danger');

            return;
        }

        $user->auto_post_slot_hours = $hours;
        $user->save();

        $this->slotHoursInput = implode(', ', $hours);
        $this->toast(sprintf('Agenda atualizada: %d slots/dia (%sh).', count($hours), implode('h, ', $hours)));
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
            'slotHours' => WindowSchedule::slotHours(),
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

        foreach (WindowSchedule::slotHours() as $hour) {
            $minute = WindowSchedule::minuteFor($day, $hour);
            $slotTime = $day->setTime($hour, $minute);
            $short = $this->findShortForSlot($slotTime, $allShorts);

            $status = match (true) {
                $short instanceof YoutubeShort => 'posted',
                $slotTime->isFuture() => 'future',
                default => 'skipped',
            };

            $slots[] = [
                'hour' => $hour,
                'minute' => $minute,
                'time_label' => sprintf('%02d:%02d', $hour, $minute),
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
        return match ($day->dayOfWeekIso) {
            1 => 'Seg', 2 => 'Ter', 3 => 'Qua', 4 => 'Qui', 5 => 'Sex', 6 => 'Sáb', default => 'Dom',
        };
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
