<?php

declare(strict_types=1);

namespace App\Services\AutoPost;

use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Date;

/**
 * N slots/dia em horas cheias, com o minuto sorteado dentro de cada hora
 * estável por (data + hora) via crc32 — sem estado, qualquer execução do
 * scheduler concorda no mesmo minuto. O scheduler roda a cada minuto e
 * ::isDueWindow() libera UMA vez por slot, no minuto sorteado.
 *
 * As horas vivem no banco (users.auto_post_slot_hours do admin, editável
 * pela /agenda) — DEFAULT_SLOT_HOURS é só o fallback quando não há valor.
 */
final class WindowSchedule
{
    public const string TIMEZONE = 'America/Sao_Paulo';

    /**
     * Fallback das horas dos slots (fuso TIMEZONE) quando o banco não tem
     * valor válido. Gap de 3h — o TikTok não flagga proximidade entre posts
     * dentro desse espaço.
     *
     * @var list<int>
     */
    public const array DEFAULT_SLOT_HOURS = [9, 12, 15, 18, 21];

    /**
     * Horas dos slots do dia, lidas do 1º user (admin) — mesma fonte de
     * verdade dos toggles auto_post_*_enabled. Valor do banco é saneado
     * (ints 0–23, únicos, ordenados); lixo ou vazio cai no default.
     * Memoizada por request/execução via once().
     *
     * @return list<int>
     */
    public static function slotHours(): array
    {
        return once(static function (): array {
            /** @var mixed $hours */
            $hours = User::query()->orderBy('id')->first()?->auto_post_slot_hours;

            return self::sanitizeHours(is_array($hours) ? $hours : null);
        });
    }

    /** True se AGORA é exatamente o minuto sorteado de algum slot do dia. */
    public static function isDueWindow(?CarbonInterface $now = null): bool
    {
        $now ??= Date::now(self::TIMEZONE);

        return array_any(
            self::slotHours(),
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

        foreach (self::slotHours() as $hour) {
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
            self::slotHours(),
        );
    }

    /**
     * Guarda única de saneamento do valor vindo do banco: só ints 0–23,
     * deduplicados e ordenados. Qualquer outra coisa (null, vazio, strings,
     * horas fora do range) cai no default — o scheduler nunca pode ficar
     * sem slots por um valor torto na coluna.
     *
     * @param  array<mixed>|null  $hours
     * @return list<int>
     */
    private static function sanitizeHours(?array $hours): array
    {
        if ($hours === null) {
            return self::DEFAULT_SLOT_HOURS;
        }

        $valid = [];
        foreach ($hours as $hour) {
            if (is_int($hour) && $hour >= 0 && $hour <= 23) {
                $valid[] = $hour;
            }
        }

        $valid = array_values(array_unique($valid));
        sort($valid);

        return $valid === [] ? self::DEFAULT_SLOT_HOURS : $valid;
    }
}
