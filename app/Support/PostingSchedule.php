<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Janelas de auto-postagem (fuso São Paulo) com minuto sorteado por dia.
 *
 * Em vez de postar na hora cheia, cada janela de 1h posta num minuto
 * pseudoaleatório, porém ESTÁVEL para o dia (ex.: hoje 09:14, amanhã 09:37).
 * A estabilidade vem de um seed determinístico (data + hora da janela): assim
 * toda execução de `schedule:run` no mesmo minuto concorda, sem persistir
 * estado. O minuto muda a cada dia (a data entra no seed) e entre janelas.
 */
final class PostingSchedule
{
    /** Início de cada janela de 1h (hora cheia, 0–23). Edite à vontade. */
    public const array WINDOWS = [9, 12, 15, 18, 21];

    public const string TIMEZONE = 'America/Sao_Paulo';

    /**
     * Retorna a hora-janela cujo minuto sorteado do dia é AGORA, ou null se
     * nenhuma. Usada no ->when() do scheduler para disparar 1x por janela.
     */
    public static function dueWindow(?CarbonInterface $now = null): ?int
    {
        $now ??= Carbon::now(self::TIMEZONE);

        foreach (self::WINDOWS as $hour) {
            if ($now->hour === $hour && $now->minute === self::minuteFor($now, $hour)) {
                return $hour;
            }
        }

        return null;
    }

    /** Minuto sorteado (0–59), estável para o dia e a janela informados. */
    public static function minuteFor(CarbonInterface $day, int $hour): int
    {
        $seed = crc32($day->format('Y-m-d').':'.$hour);

        return abs($seed) % 60;
    }
}
