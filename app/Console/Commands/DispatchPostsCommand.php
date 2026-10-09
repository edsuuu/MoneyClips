<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\PostStatusEnum;
use App\Jobs\PublishPostJob;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Services\API\Discord\DiscordNotifierService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Roda a cada minuto. Nunca posta fora de hora e nunca reposta: o agendado
 * que passou da tolerância vira Missed, o que travou em Posting vira Failed
 * (pode ter saído na plataforma) e tentar de novo é sempre ação do dono.
 */
final class DispatchPostsCommand extends Command
{
    /** @var string */
    protected $signature = 'posts:dispatch';

    /** @var string */
    protected $description = 'Expira os agendados que perderam o horário, falha os travados e despacha os que chegaram na hora.';

    public function handle(DiscordNotifierService $discord): int
    {
        $this->expireMissed($discord);
        $this->failStuck($discord);
        $this->dispatchDue($discord);

        return self::SUCCESS;
    }

    private function expireMissed(DiscordNotifierService $discord): void
    {
        $missed = SocialPost::query()
            ->with(['youtubeShort', 'socialAccount'])
            ->where('status', PostStatusEnum::Scheduled)
            ->where('scheduled_for', '<', now()->subMinutes((int) config('posting.grace_minutes')))
            ->get();

        foreach ($missed as $post) {
            if (! $this->transition($post, PostStatusEnum::Scheduled, ['status' => PostStatusEnum::Missed])) {
                continue;
            }

            Log::channel('daily')->warning('[WARN][Posting] Post perdeu o horário.', ['post_id' => $post->id]);
            $discord->warning('⏰ Post perdeu o horário', $this->describe($post));
        }
    }

    private function failStuck(DiscordNotifierService $discord): void
    {
        $stuck = SocialPost::query()
            ->with(['youtubeShort', 'socialAccount'])
            ->where('status', PostStatusEnum::Posting)
            ->where('started_at', '<', now()->subMinutes((int) config('posting.stuck_minutes')))
            ->get();

        $error = 'Resultado desconhecido: a postagem ficou sem resposta. Confira na plataforma antes de tentar de novo.';

        foreach ($stuck as $post) {
            if (! $this->transition($post, PostStatusEnum::Posting, ['status' => PostStatusEnum::Failed, 'error' => $error])) {
                continue;
            }

            Log::channel('daily')->error('[ERRO][Posting] Post travado em Posting marcado como falha.', ['post_id' => $post->id]);
            $discord->error('❌ Post sem resposta', $this->describe($post).PHP_EOL.$error);
        }
    }

    private function dispatchDue(DiscordNotifierService $discord): void
    {
        $due = SocialPost::query()
            ->with(['youtubeShort', 'socialAccount'])
            ->where('status', PostStatusEnum::Scheduled)
            ->where('scheduled_for', '<=', now())
            ->oldest('scheduled_for')
            ->get();

        foreach ($due as $post) {
            $blocked = $this->blockedReason($post->socialAccount);
            if (! is_null($blocked)) {
                if ($this->transition($post, PostStatusEnum::Scheduled, ['status' => PostStatusEnum::Failed, 'error' => $blocked])) {
                    Log::channel('daily')->error('[ERRO][Posting] Conta bloqueada na hora do post.', ['post_id' => $post->id, 'error' => $blocked]);
                    $discord->error('❌ Post não saiu', $this->describe($post).PHP_EOL.$blocked);
                }

                continue;
            }

            $claimed = $this->transition($post, PostStatusEnum::Scheduled, [
                'status' => PostStatusEnum::Posting,
                'started_at' => now(),
                'attempts' => DB::raw('attempts + 1'),
            ]);
            if (! $claimed) {
                continue;
            }

            Log::channel('daily')->info('[INFO][Posting] Post despachado.', ['post_id' => $post->id]);
            dispatch(new PublishPostJob($post->id));
        }
    }

    /**
     * Claim atômico: o UPDATE só vinga se a linha ainda está no estado lido.
     * Dois ticks, dois workers ou o dono cancelando no meio nunca movem a
     * mesma linha duas vezes.
     *
     * @param  array<string, mixed>  $values
     */
    private function transition(SocialPost $post, PostStatusEnum $from, array $values): bool
    {
        return SocialPost::query()->whereKey($post->id)->where('status', $from)->update($values) === 1;
    }

    private function blockedReason(SocialAccount $account): ?string
    {
        if (! $account->is_active) {
            return 'Conta desativada em /contas.';
        }

        if ($account->session_status === SocialAccount::SESSION_INVALID) {
            return 'Sessão da conta inválida: reconecte em /contas.';
        }

        return null;
    }

    private function describe(SocialPost $post): string
    {
        return sprintf(
            '%s · %s (%s) · %s',
            $post->youtubeShort->title ?? $post->youtubeShort->youtube_id,
            $post->socialAccount->name,
            $post->socialAccount->platform,
            $post->scheduled_for->format('d/m H:i'),
        );
    }
}
