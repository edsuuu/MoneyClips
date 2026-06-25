<?php

declare(strict_types=1);

namespace App\Livewire\Agenda;

use App\Livewire\Concerns\WithToasts;
use App\Models\AutoPostSettings;
use App\Models\YoutubeShort;
use App\Services\AutoPost\AutoPostDispatcher;
use App\Services\AutoPost\WindowSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Livewire\Component;
use Throwable;

/**
 * /agenda — visão semanal dos 5 slots/dia (9/12/15/18/21h, SP) com o minuto
 * sorteado por dia e o status de cada disparo: postado, próximo, futuro ou
 * pulado. Lê AutoPostSettings pra mostrar quais plataformas estão ativas.
 */
final class Index extends Component
{
    use WithToasts;

    private const string TIMEZONE = WindowSchedule::TIMEZONE;

    /** Tolerância (minutos) pra casar dispatched_at com o minuto sorteado. */
    private const int MATCH_TOLERANCE_MIN = 3;

    /**
     * Dispara o AutoPostDispatcher imediatamente, ignorando o lock da janela
     * atual. Aceita só $slotDate=hoje (SP) — repostar slot de dia passado não
     * faz sentido (já era pra ter postado naquele momento). Sorteia o próximo
     * Short do estoque e publica nas plataformas habilitadas.
     */
    public function forceDispatch(string $slotDate): void
    {
        try {
            $now = Date::now(self::TIMEZONE);
            if ($slotDate !== $now->format('Y-m-d')) {
                $this->toast('Só dá pra forçar disparo de slots do dia atual.', 'danger');

                return;
            }

            $key = WindowSchedule::windowKey();
            if ($key !== null) {
                Cache::forget($key);
            }

            resolve(AutoPostDispatcher::class)->run(1);
            $this->toast('Disparo forçado enviado para o estoque.');
        } catch (Throwable $throwable) {
            Log::error('[Agenda] Falha ao forçar disparo.', ['error' => $throwable->getMessage()]);
            $this->toast('Falha ao forçar disparo: '.$throwable->getMessage(), 'danger');
        }
    }

    /** Liga/desliga YouTube na auto-postagem. Persiste em auto_post_settings. */
    public function toggleYoutube(): void
    {
        $settings = AutoPostSettings::current();
        $settings->youtube_enabled = ! $settings->youtube_enabled;
        $this->persistSettings($settings, 'YouTube', $settings->youtube_enabled);
    }

    /** Liga/desliga TikTok na auto-postagem. Persiste em auto_post_settings. */
    public function toggleTiktok(): void
    {
        $settings = AutoPostSettings::current();
        $settings->tiktok_enabled = ! $settings->tiktok_enabled;
        $this->persistSettings($settings, 'TikTok', $settings->tiktok_enabled);
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

        return view('livewire.agenda.index', [
            'days' => $days,
            'now' => $now,
            'weekStart' => $monday,
            'weekEnd' => $sunday,
            'settings' => AutoPostSettings::current(),
            'slotHours' => WindowSchedule::SLOT_HOURS,
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

        foreach (WindowSchedule::SLOT_HOURS as $hour) {
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

    private function dayLabel(CarbonImmutable $day): string
    {
        return match ($day->dayOfWeekIso) {
            1 => 'Seg', 2 => 'Ter', 3 => 'Qua', 4 => 'Qui', 5 => 'Sex', 6 => 'Sáb', default => 'Dom',
        };
    }

    private function persistSettings(AutoPostSettings $settings, string $label, bool $value): void
    {
        $userId = auth()->id();
        $settings->updated_by_user_id = is_numeric($userId) ? max(0, (int) $userId) : null;
        $settings->save();
        $this->toast(sprintf('%s %s.', $label, $value ? 'ativado' : 'pausado'));
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
