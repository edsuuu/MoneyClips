<?php

declare(strict_types=1);

namespace App\Services\AutoPost;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Date;

/**
 * Janelas de postagem por dia da semana, baseadas nos "melhores horários" do
 * TikTok. Cada dia tem 1+ ranges (HH:MM–HH:MM, fuso TIMEZONE); o scheduler
 * roda a cada minuto e ::isDueWindow() libera UMA vez por range, num minuto
 * sorteado dentro dele (estável por dia + range, via crc32 — sem estado).
 *
 * @phpstan-type Range array{start: string, end: string}
 */
final class WindowSchedule
{
    public const string TIMEZONE = 'America/Sao_Paulo';

    /**
     * Schedule semanal: ISO 8601 day-of-week (1=Seg ... 7=Dom) → list de ranges.
     * Fonte: tabela "Melhores Horários Para Postar no TikTok".
     *
     * @var array<int, list<Range>>
     */
    public const array SCHEDULE = [
        1 => [['start' => '11:00', 'end' => '13:00'], ['start' => '19:00', 'end' => '21:00']],
        2 => [['start' => '14:00', 'end' => '15:00'], ['start' => '20:00', 'end' => '21:30']],
        3 => [['start' => '12:00', 'end' => '14:00'], ['start' => '18:00', 'end' => '21:00']],
        4 => [['start' => '11:00', 'end' => '13:00'], ['start' => '19:00', 'end' => '22:00']],
        5 => [['start' => '12:00', 'end' => '15:00'], ['start' => '20:00', 'end' => '23:00']],
        6 => [['start' => '10:00', 'end' => '12:00'], ['start' => '18:00', 'end' => '22:00']],
        7 => [['start' => '09:00', 'end' => '12:00'], ['start' => '18:00', 'end' => '21:00']],
    ];

    /** True se AGORA é o minuto sorteado de algum range do dia. */
    public static function isDueWindow(?CarbonInterface $now = null): bool
    {
        $now ??= Date::now(self::TIMEZONE);
        $ranges = self::SCHEDULE[$now->dayOfWeekIso] ?? [];
        $nowMinuteOfDay = $now->hour * 60 + $now->minute;

        return array_any($ranges, fn (array $range, int $index): bool => $nowMinuteOfDay === self::minuteOfDayFor($now, $index, $range));
    }

    /**
     * Minuto-do-dia (0–1439) sorteado pra um range específico, estável por
     * (data + índice do range). Sempre cai DENTRO do range [start, end).
     *
     * @param  Range  $range
     */
    public static function minuteOfDayFor(CarbonInterface $day, int $rangeIndex, array $range): int
    {
        $startMin = self::toMinutes($range['start']);
        $endMin = self::toMinutes($range['end']);
        $span = max(1, $endMin - $startMin);
        $seed = $day->format('Y-m-d').':'.$rangeIndex;

        return $startMin + (abs(crc32($seed)) % $span);
    }

    /**
     * Chave do lock de idempotência da janela ativa (1 execução por range).
     * Retorna null se AGORA não está dentro de nenhum range do dia.
     */
    public static function windowKey(?CarbonInterface $now = null): ?string
    {
        $now ??= Date::now(self::TIMEZONE);
        $ranges = self::SCHEDULE[$now->dayOfWeekIso] ?? [];
        $nowMinuteOfDay = $now->hour * 60 + $now->minute;

        foreach ($ranges as $index => $range) {
            $startMin = self::toMinutes($range['start']);
            $endMin = self::toMinutes($range['end']);
            if ($nowMinuteOfDay >= $startMin && $nowMinuteOfDay < $endMin) {
                return 'auto-post:window:'.$now->format('Y-m-d').':'.$index;
            }
        }

        return null;
    }

    /**
     * Lista os horários sorteados do DIA atual (debug/UI). Retorna ['HH:MM', ...].
     *
     * @return list<string>
     */
    public static function todaysFiringTimes(?CarbonInterface $day = null): array
    {
        $day ??= Date::now(self::TIMEZONE);
        $ranges = self::SCHEDULE[$day->dayOfWeekIso] ?? [];
        $out = [];

        foreach ($ranges as $index => $range) {
            $minute = self::minuteOfDayFor($day, $index, $range);
            $out[] = sprintf('%02d:%02d', intdiv($minute, 60), $minute % 60);
        }

        return $out;
    }

    private static function toMinutes(string $hhmm): int
    {
        [$h, $m] = array_map(intval(...), explode(':', $hhmm));

        return $h * 60 + $m;
    }
}
