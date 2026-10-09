<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\PostStatusEnum;
use App\Models\SocialAccount;
use App\Services\API\Discord\DiscordNotifierService;
use App\Services\Posting\PostSchedulerService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;

/**
 * Rede de segurança do modo Automático: o Short marcado como pronto já entra
 * na hora (`markReady`); aqui entra o que faltou. Depois de preencher, todo
 * pronto já está agendado, então os agendados futuros de cada conta são o
 * estoque: menos do que cabe num dia (`per_day`, limitado ao nº de horários
 * da grade) = menos de 1 dia de postagem → Discord, no máximo 1 aviso por
 * conta por dia.
 */
final class FillPostsCommand extends Command
{
    /** @var string */
    protected $signature = 'posts:fill';

    /** @var string */
    protected $description = 'Agenda os Shorts prontos que faltam nas contas Automáticas e avisa no Discord quando o estoque cobre menos de 1 dia.';

    public function handle(PostSchedulerService $scheduler, DiscordNotifierService $discord): int
    {
        $filled = $scheduler->fillAutoAccounts();
        if ($filled > 0) {
            Log::channel('daily')->info('[INFO][Posting] Shorts prontos agendados nas contas Automáticas.', ['count' => $filled]);
        }

        $perDay = min((int) config('posting.per_day'), count(Config::array('posting.times')));

        foreach ($scheduler->autoAccounts()->get() as $account) {
            $upcoming = $account->socialPosts()->where('status', PostStatusEnum::Scheduled)->where('scheduled_for', '>', now())->count();
            if ($upcoming >= $perDay) {
                continue;
            }

            if (! Cache::add('posting:low-stock:'.$account->id, true, now()->addDay())) {
                continue;
            }

            Log::channel('daily')->warning('[WARN][Posting] Estoque de Shorts cobre menos de 1 dia.', ['account_id' => $account->id, 'upcoming' => $upcoming]);
            $discord->warning('📉 Estoque de Shorts acabando', $this->describe($account, $upcoming, $perDay));
        }

        $this->info(sprintf('%d post(s) agendado(s).', $filled));

        return self::SUCCESS;
    }

    private function describe(SocialAccount $account, int $upcoming, int $perDay): string
    {
        return sprintf(
            '%s (%s): %d agendado(s), menos de 1 dia de postagem (%d por dia). Marque mais Shorts como prontos em /meus-videos.',
            $account->name,
            $account->platform,
            $upcoming,
            $perDay,
        );
    }
}
