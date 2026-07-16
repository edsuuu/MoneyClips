<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ServiceHeartbeat;
use App\Services\DiscordNotifier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Sentinela dos microserviços (roda a cada minuto): heartbeat mais velho que
 * 90s → Discord error, 1× por queda (Cache::add sem TTL); quando o serviço
 * volta, avisa a recuperação e limpa o dedupe.
 */
final class CheckServiceHeartbeatsCommand extends Command
{
    /** @var string */
    protected $signature = 'observability:check-heartbeats';

    /** @var string */
    protected $description = 'Alerta no Discord quando um microserviço para de mandar heartbeat (> 90s).';

    public function handle(DiscordNotifier $discord): int
    {
        foreach (ServiceHeartbeat::query()->get() as $heartbeat) {
            $downKey = 'observability:down:'.$heartbeat->service;

            if (! $heartbeat->isOnline()) {
                // Cache::add é atômico — 1 alerta por queda, sem TTL (limpo na volta).
                if (Cache::add($downKey, true)) {
                    $discord->error(
                        '🔴 Microserviço fora do ar',
                        sprintf(
                            '%s (%s) sem heartbeat desde %s.%sVerifique o processo (pm2/systemd) e a rede.',
                            $heartbeat->service,
                            $heartbeat->hostname ?? 'host desconhecido',
                            $heartbeat->last_seen_at->timezone('America/Sao_Paulo')->format('d/m H:i:s'),
                            PHP_EOL,
                        ),
                    );
                    $this->warn(sprintf('%s fora do ar.', $heartbeat->service));
                }

                continue;
            }

            if (Cache::pull($downKey) !== null) {
                $discord->success(
                    '🟢 Microserviço recuperado',
                    sprintf('%s voltou a mandar heartbeat.', $heartbeat->service),
                );
                $this->info(sprintf('%s recuperado.', $heartbeat->service));
            }
        }

        return self::SUCCESS;
    }
}
