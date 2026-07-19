<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ScheduleSlot;
use App\Models\SocialPost;
use App\Services\Api\Discord\DiscordNotifierService;
use App\Services\AutoPost\AutoPostDispatcherService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

final class CheckMissedAutoPostCommand extends Command
{
    private const int LOOKBACK_HOURS = 6;

    private const int STALE_PENDING_HOURS = 2;

    /** @var string */
    protected $signature = 'auto-post:check-missed';

    /** @var string */
    protected $description = 'Avisa no Discord sobre slots pulados ou com falha nas últimas 6h.';

    public function handle(DiscordNotifierService $discord): int
    {
        $now = CarbonImmutable::now();
        $cutoff = $now->subHours(self::LOOKBACK_HOURS);
        $grace = $now->subMinutes(AutoPostDispatcherService::GRACE_MINUTES);

        $slots = ScheduleSlot::query()
            ->with('socialPosts')
            ->whereBetween('slot_date', [$cutoff->toDateString(), $now->toDateString()])
            ->get()
            ->filter(fn (ScheduleSlot $slot): bool => $slot->scheduledAt()->between($cutoff, $grace));

        $alerts = 0;

        foreach ($slots as $slot) {
            $label = $slot->slot_date->format('d/m').' '.$slot->timeLabel();

            if ($slot->dispatched_at === null && $slot->is_active && $slot->youtube_short_id !== null) {
                $alerts += $this->alertOnce($discord, 'skipped:'.$slot->id, '⚠️ Slot pulado na auto-postagem', sprintf(
                    'Slot %s (SP) passou sem postagem.%sAbra a /agenda e clique "Forçar agora".',
                    $label,
                    PHP_EOL,
                ));

                continue;
            }

            if ($slot->dispatched_at === null && $slot->is_active && $slot->youtube_short_id === null) {
                $alerts += $this->alertOnce($discord, 'empty:'.$slot->id, '⚠️ Slot sem vídeo atribuído', sprintf(
                    'Slot %s (SP) passou em branco — nenhum vídeo estava atribuído.%sAtribua vídeos na /agenda ou use "Gerar próxima semana".',
                    $label,
                    PHP_EOL,
                ));

                continue;
            }

            if ($slot->dispatched_at !== null && $slot->socialPosts->isNotEmpty()) {
                $failed = $slot->socialPosts->whereIn('status', ['failed', 'restricted']);
                $pending = $slot->socialPosts->whereIn('status', ['queued', 'processing']);

                if ($pending->isEmpty() && $failed->count() === $slot->socialPosts->count()) {
                    $reasons = $failed
                        ->map(fn (SocialPost $post): string => sprintf('- %s: %s', $post->platform, $post->error ?? 'sem detalhe'))
                        ->implode(PHP_EOL);

                    $alerts += $this->alertOnce($discord, 'failed:'.$slot->id, '❌ Slot falhou em todas as plataformas', sprintf(
                        'Slot %s (SP):%s%s',
                        $label,
                        PHP_EOL,
                        $reasons,
                    ));

                    continue;
                }

                // Pendência presa: post assíncrono cujo desfecho nunca chegou
                // (uploader reiniciado com job na fila em memória, webhook
                // esgotado). Sem este alerta a linha `queued` suprimiria o
                // aviso de falha pra sempre.
                if ($pending->isNotEmpty() && $slot->scheduledAt()->lessThan($now->subHours(self::STALE_PENDING_HOURS))) {
                    $platforms = $pending
                        ->map(fn (SocialPost $post): string => sprintf('- %s: %s', $post->platform, $post->status))
                        ->implode(PHP_EOL);

                    $alerts += $this->alertOnce($discord, 'stale:'.$slot->id, '⚠️ Post pendente há tempo demais', sprintf(
                        'Slot %s (SP) segue sem desfecho após %dh:%s%s%sConfira o uploader/observabilidade e o vídeo no TikTok antes de repostar.',
                        $label,
                        self::STALE_PENDING_HOURS,
                        PHP_EOL,
                        $platforms,
                        PHP_EOL,
                    ));
                }
            }
        }

        $this->info($alerts === 0
            ? 'Nenhum alerta novo nas últimas '.self::LOOKBACK_HOURS.'h.'
            : sprintf('%d alerta(s) enviado(s).', $alerts));

        return self::SUCCESS;
    }

    private function alertOnce(DiscordNotifierService $discord, string $key, string $title, string $message): int
    {
        if (! Cache::add('auto-post:missed-alert:'.$key, true, now()->addDay())) {
            return 0;
        }

        $discord->warning($title, $message);
        $this->warn($title.' — '.$key);

        return 1;
    }
}
