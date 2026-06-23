<?php

declare(strict_types=1);

namespace App\Services\AutoPost;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Date;

/**
 * Janelas de postagem por dia da semana, baseadas nos "melhores horários" do
 * TikTok. Cada dia tem 1+ ranges (HH:MM–HH:MM, fuso TIMEZONE) e cada range é
 * dividido em N slots (SLOTS_PER_RANGE) — o scheduler libera 1 postagem por
 * slot. Sem estado: o sorteio é estável por (data + range + slot) via crc32.
 *
 * Atualmente: 2 ranges/dia × 2 slots = 4 postagens/dia, todos os dias.
 *
 * @phpstan-type Range array{start: string, end: string}
 */
final class WindowSchedule
{
    public const string TIMEZONE = 'America/Sao_Paulo';

    /** Quantos posts por range. Total/dia = sum(ranges) × SLOTS_PER_RANGE. */
    public const int SLOTS_PER_RANGE = 2;

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

    /** True se AGORA é exatamente o minuto sorteado de algum slot do dia. */
    public static function isDueWindow(?CarbonInterface $now = null): bool
    {
        $now ??= Date::now(self::TIMEZONE);
        $nowMinuteOfDay = $now->hour * 60 + $now->minute;
        $ranges = self::SCHEDULE[$now->dayOfWeekIso] ?? [];

        foreach ($ranges as $rangeIndex => $range) {
            foreach (self::slotsForRange($now, $rangeIndex, $range) as $slot) {
                if ($slot === $nowMinuteOfDay) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Lista os minutos sorteados de um range. O range é dividido em
     * SLOTS_PER_RANGE buckets iguais; cada slot sorteia 1 minuto DENTRO do seu
     * bucket. Distribuição uniforme, sem colisão entre slots do mesmo range.
     *
     * @param  Range  $range
     * @return list<int> minutos-do-dia (0–1439), ordenados.
     */
    public static function slotsForRange(CarbonInterface $day, int $rangeIndex, array $range): array
    {
        $startMin = self::toMinutes($range['start']);
        $endMin = self::toMinutes($range['end']);
        $span = max(1, $endMin - $startMin);
        $bucketSize = max(1, intdiv($span, self::SLOTS_PER_RANGE));
        $out = [];

        for ($i = 0; $i < self::SLOTS_PER_RANGE; $i++) {
            $bucketStart = $startMin + $i * $bucketSize;
            // último slot estende até o fim do range pra absorver o resto da divisão.
            $bucketEnd = ($i === self::SLOTS_PER_RANGE - 1) ? $endMin : $bucketStart + $bucketSize;
            $bucketSpan = max(1, $bucketEnd - $bucketStart);
            $seed = $day->format('Y-m-d').':'.$rangeIndex.':'.$i;
            $out[] = $bucketStart + (abs(crc32($seed)) % $bucketSpan);
        }

        return $out;
    }

    /**
     * Chave de idempotência do slot ativo (1 execução por slot). Retorna null
     * se AGORA não coincide com nenhum slot do dia.
     */
    public static function windowKey(?CarbonInterface $now = null): ?string
    {
        $now ??= Date::now(self::TIMEZONE);
        $nowMinuteOfDay = $now->hour * 60 + $now->minute;
        $ranges = self::SCHEDULE[$now->dayOfWeekIso] ?? [];

        foreach ($ranges as $rangeIndex => $range) {
            foreach (self::slotsForRange($now, $rangeIndex, $range) as $slotIndex => $slot) {
                if ($slot === $nowMinuteOfDay) {
                    return 'auto-post:window:'.$now->format('Y-m-d').':'.$rangeIndex.':'.$slotIndex;
                }
            }
        }

        return null;
    }

    /**
     * Lista todos os horários sorteados do DIA (debug/UI). ['HH:MM', ...] ordenado.
     *
     * @return list<string>
     */
    public static function todaysFiringTimes(?CarbonInterface $day = null): array
    {
        $day ??= Date::now(self::TIMEZONE);
        $ranges = self::SCHEDULE[$day->dayOfWeekIso] ?? [];
        $minutes = [];

        foreach ($ranges as $rangeIndex => $range) {
            foreach (self::slotsForRange($day, $rangeIndex, $range) as $slot) {
                $minutes[] = $slot;
            }
        }

        sort($minutes);

        return array_map(
            static fn (int $m): string => sprintf('%02d:%02d', intdiv($m, 60), $m % 60),
            $minutes,
        );
    }

    private static function toMinutes(string $hhmm): int
    {
        [$h, $m] = array_map(intval(...), explode(':', $hhmm));

        return $h * 60 + $m;
    }
}
