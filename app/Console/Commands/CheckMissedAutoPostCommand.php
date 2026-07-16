<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ScheduleSlot;
use App\Models\SocialPost;
use App\Services\AutoPost\AutoPost;
use App\Services\AutoPost\AutoPostDispatcher;
use App\Services\DiscordNotifier;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Sentinela da agenda: varre as últimas 6h e avisa no Discord (1× por slot,
 * dedupe via Cache::add) sobre:
 *  - slot com vídeo que passou do horário sem despacho ("Forçar agora" resolve);
 *  - slot ativo sem vídeo atribuído que passou em branco;
 *  - slot despachado cujas plataformas falharam todas.
 *
 * Não posta nada — só avisa o operador.
 */
final class CheckMissedAutoPostCommand extends Command
{
    /** Quanto pra trás olhamos. */
    private const int LOOKBACK_HOURS = 6;

    /** @var string */
    protected $signature = 'auto-post:check-missed';

    /** @var string */
    protected $description = 'Avisa no Discord sobre slots pulados ou com falha nas últimas 6h.';

    public function handle(DiscordNotifier $discord): int
    {
        $now = CarbonImmutable::now(AutoPost::TIMEZONE);
        $cutoff = $now->subHours(self::LOOKBACK_HOURS);
        $grace = $now->subMinutes(AutoPostDispatcher::GRACE_MINUTES);

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
                }
            }
        }

        $this->info($alerts === 0
            ? 'Nenhum alerta novo nas últimas '.self::LOOKBACK_HOURS.'h.'
            : sprintf('%d alerta(s) enviado(s).', $alerts));

        return self::SUCCESS;
    }

    /** Cache::add é atômico — 1 alerta por chave, vence em 24h. */
    private function alertOnce(DiscordNotifier $discord, string $key, string $title, string $message): int
    {
        if (! Cache::add('auto-post:missed-alert:'.$key, true, now()->addDay())) {
            return 0;
        }

        $discord->warning($title, $message);
        $this->warn($title.' — '.$key);

        return 1;
    }
}
