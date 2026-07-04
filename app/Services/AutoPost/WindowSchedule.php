<?php

declare(strict_types=1);

namespace App\Services\AutoPost;

use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Date;

/**
 * Agenda da auto-postagem: horários exatos "HH:MM" por dia da semana (ISO
 * 1=Seg..7=Dom), vindos do banco (users.auto_post_schedule do admin, editável
 * pela /agenda). O scheduler roda a cada minuto e ::isDueWindow() libera UMA
 * vez por horário, no minuto exato.
 *
 * Sem agenda configurada, o fallback é o comportamento clássico: horas fixas
 * (DEFAULT_SLOT_HOURS) com minuto sorteado por (data + hora) via crc32 —
 * sem estado, qualquer execução do scheduler concorda no mesmo minuto.
 */
final class WindowSchedule
{
    public const string TIMEZONE = 'America/Sao_Paulo';

    /**
     * Horas do fallback (fuso TIMEZONE), usadas só quando não há agenda no
     * banco. Gap de 3h — o TikTok não flagga proximidade dentro desse espaço.
     *
     * @var list<int>
     */
    public const array DEFAULT_SLOT_HOURS = [9, 12, 15, 18, 21];

    /**
     * Horários ("HH:MM", ordenados) dos slots de um DIA específico. Dia sem
     * chave na agenda configurada = sem postagens naquele dia da semana.
     *
     * @return list<string>
     */
    public static function timesFor(CarbonInterface $day): array
    {
        $schedule = self::schedule();
        if ($schedule === null) {
            return array_map(
                static fn (int $hour): string => sprintf('%02d:%02d', $hour, self::minuteFor($day, $hour)),
                self::DEFAULT_SLOT_HOURS,
            );
        }

        return $schedule[$day->dayOfWeekIso] ?? [];
    }

    /** True se AGORA é exatamente um dos horários do dia. */
    public static function isDueWindow(?CarbonInterface $now = null): bool
    {
        $now ??= Date::now(self::TIMEZONE);

        return in_array($now->format('H:i'), self::timesFor($now), true);
    }

    /** Minuto (0–59) sorteado pra uma hora do fallback, estável por (data + hora). */
    public static function minuteFor(CarbonInterface $day, int $hour): int
    {
        return abs(crc32($day->format('Y-m-d').':'.$hour)) % 60;
    }

    /**
     * Chave do lock de idempotência do slot ativo (1 execução por horário).
     * Retorna null se AGORA não coincide com nenhum horário do dia.
     */
    public static function windowKey(?CarbonInterface $now = null): ?string
    {
        $now ??= Date::now(self::TIMEZONE);

        if (! self::isDueWindow($now)) {
            return null;
        }

        return 'auto-post:window:'.$now->format('Y-m-d:H:i');
    }

    /**
     * Lista os horários do DIA (debug/UI). ['HH:MM', ...] em ordem.
     *
     * @return list<string>
     */
    public static function todaysFiringTimes(?CarbonInterface $day = null): array
    {
        return self::timesFor($day ?? Date::now(self::TIMEZONE));
    }

    /**
     * Agenda saneada do banco (dia ISO => horários) ou null quando nunca foi
     * configurada. Lê do 1º user (admin) — mesma fonte de verdade dos toggles
     * auto_post_*_enabled. Memoizada por request/execução via once().
     *
     * @return array<int, list<string>>|null
     */
    private static function schedule(): ?array
    {
        return once(static function (): ?array {
            /** @var mixed $raw */
            $raw = User::query()->orderBy('id')->first()?->auto_post_schedule;

            return is_array($raw) ? self::sanitizeSchedule($raw) : null;
        });
    }

    /**
     * Guarda única de saneamento do valor vindo do banco: por dia (1–7), só
     * strings "HH:MM" válidas, deduplicadas e ordenadas. Dia ausente ou com
     * lixo vira lista vazia (= sem postagens) — configuração explícita não
     * ressuscita o fallback.
     *
     * @param  array<mixed>  $raw
     * @return array<int, list<string>>
     */
    private static function sanitizeSchedule(array $raw): array
    {
        $schedule = [];
        foreach (range(1, 7) as $dayOfWeek) {
            $times = $raw[$dayOfWeek] ?? [];
            $valid = [];
            foreach (is_array($times) ? $times : [] as $time) {
                if (is_string($time) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time) === 1) {
                    $valid[] = $time;
                }
            }

            $valid = array_values(array_unique($valid));
            sort($valid);
            $schedule[$dayOfWeek] = $valid;
        }

        return $schedule;
    }
}
