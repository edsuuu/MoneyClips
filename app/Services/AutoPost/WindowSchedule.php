<?php

declare(strict_types=1);

namespace App\Services\AutoPost;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Date;

/**
 * 5 slots/dia com gap mínimo de 3h entre eles. Cada slot é uma HORA cheia
 * (SLOT_HOURS) e o minuto sorteado dentro dela é estável por (data + hora)
 * via crc32 — sem estado, qualquer execução do scheduler concorda no mesmo
 * minuto. O scheduler roda a cada minuto e ::isDueWindow() libera UMA vez
 * por slot, no minuto sorteado.
 */
final class WindowSchedule
{
    public const string TIMEZONE = 'America/Sao_Paulo';

    /**
     * Horas dos 5 slots diários (fuso TIMEZONE). Gaps fixos de 3h entre eles
     * — o TikTok não flagga proximidade entre posts dentro desse espaço.
     *
     * @var list<int>
     */
    public const array SLOT_HOURS = [9, 12, 15, 18, 21];

    /** True se AGORA é exatamente o minuto sorteado de algum slot do dia. */
    public static function isDueWindow(?CarbonInterface $now = null): bool
    {
        $now ??= Date::now(self::TIMEZONE);

        return array_any(
            self::SLOT_HOURS,
            fn (int $hour): bool => $now->hour === $hour && $now->minute === self::minuteFor($now, $hour),
        );
    }

    /** Minuto (0–59) sorteado pra uma hora, estável por (data + hora). */
    public static function minuteFor(CarbonInterface $day, int $hour): int
    {
        return abs(crc32($day->format('Y-m-d').':'.$hour)) % 60;
    }

    /**
     * Chave do lock de idempotência do slot ativo (1 execução por hora-slot).
     * Retorna null se AGORA não coincide com nenhum slot.
     */
    public static function windowKey(?CarbonInterface $now = null): ?string
    {
        $now ??= Date::now(self::TIMEZONE);

        foreach (self::SLOT_HOURS as $hour) {
            if ($now->hour === $hour && $now->minute === self::minuteFor($now, $hour)) {
                return 'auto-post:window:'.$now->format('Y-m-d:H');
            }
        }

        return null;
    }

    /**
     * Lista os horários sorteados do DIA (debug/UI). ['HH:MM', ...] na ordem
     * dos slots.
     *
     * @return list<string>
     */
    public static function todaysFiringTimes(?CarbonInterface $day = null): array
    {
        $day ??= Date::now(self::TIMEZONE);

        return array_map(
            static fn (int $hour): string => sprintf('%02d:%02d', $hour, self::minuteFor($day, $hour)),
            self::SLOT_HOURS,
        );
    }
}
