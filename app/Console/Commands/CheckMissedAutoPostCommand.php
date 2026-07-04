<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\YoutubeShort;
use App\Services\AutoPost\WindowSchedule;
use App\Services\DiscordNotifier;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;

/**
 * Verifica se algum slot das últimas 6h foi pulado (passou do minuto sorteado
 * sem nenhuma reserva no estoque). Notifica no Discord 1x por slot pra o
 * operador conseguir reagir (ex.: clicar 'Forçar agora' na /agenda).
 *
 * Roda no scheduler a cada 10 minutos — janela de 6h cobre todos os 5 slots
 * do dia sem floodar o Discord (Cache::add por chave de slot dedupica).
 */
final class CheckMissedAutoPostCommand extends Command
{
    /** Tolerância (minutos) pra casar dispatched_at com o minuto sorteado. */
    private const int MATCH_TOLERANCE_MIN = 3;

    /** Quanto tempo o slot é considerado "pulado" sem aparecer. */
    private const int GRACE_MIN = 5;

    /** Quanto pra trás olhamos. */
    private const int LOOKBACK_HOURS = 6;

    /** @var string */
    protected $signature = 'auto-post:check-missed';

    /** @var string */
    protected $description = 'Avisa no Discord se algum slot da auto-postagem foi pulado nas últimas 6h.';

    public function handle(DiscordNotifier $discord): int
    {
        $now = Date::now(WindowSchedule::TIMEZONE);
        $missed = $this->findMissedSlots($now);

        if ($missed === []) {
            $this->info('Nenhum slot pulado nas últimas '.self::LOOKBACK_HOURS.'h.');

            return self::SUCCESS;
        }

        foreach ($missed as $slot) {
            $alertKey = 'auto-post:missed-alert:'.$slot['key'];

            // Cache::add é atômico — 1 alerta por slot, vence em 24h pra não
            // floodar o Discord com a mesma janela em check-missed sucessivos.
            if (! Cache::add($alertKey, true, $now->copy()->addDay())) {
                continue;
            }

            $discord->warning(
                '⚠️ Slot pulado na auto-postagem',
                sprintf(
                    'Slot %s SP passou há %d min sem postagem.%sAbra /agenda e clique "Forçar agora" pra disparar o próximo Short.',
                    $slot['label'],
                    $slot['minutes_ago'],
                    PHP_EOL,
                ),
            );

            $this->warn(sprintf('Alerta enviado: %s (%d min atrás).', $slot['label'], $slot['minutes_ago']));
        }

        return self::SUCCESS;
    }

    /**
     * @return list<array{key: string, label: string, datetime: CarbonImmutable, minutes_ago: int}>
     */
    private function findMissedSlots(CarbonImmutable $now): array
    {
        $cutoff = $now->copy()->subHours(self::LOOKBACK_HOURS);
        $missed = [];

        // Olha o intervalo lookback → agora. Iteramos hoje e ontem (cobre virada).
        foreach ([$now->copy()->subDay(), $now] as $day) {
            foreach (WindowSchedule::timesFor($day) as $time) {
                $slotTime = $day->setTimeFromTimeString($time);
                if ($slotTime->isBefore($cutoff)) {
                    continue;
                }

                if ($slotTime->isAfter($now->copy()->subMinutes(self::GRACE_MIN))) {
                    continue;
                }

                if ($this->slotWasPosted($slotTime)) {
                    continue;
                }

                $missed[] = [
                    'key' => $slotTime->format('Y-m-d:H:i'),
                    'label' => $slotTime->format('d/m H:i'),
                    'datetime' => $slotTime,
                    'minutes_ago' => (int) round($slotTime->diffInMinutes($now, true)),
                ];
            }
        }

        return $missed;
    }

    private function slotWasPosted(CarbonImmutable $slotTime): bool
    {
        $from = $slotTime->copy()->subMinutes(self::MATCH_TOLERANCE_MIN);
        $to = $slotTime->copy()->addMinutes(self::MATCH_TOLERANCE_MIN);

        return YoutubeShort::query()
            ->whereBetween('dispatched_at', [$from, $to])
            ->exists();
    }
}
