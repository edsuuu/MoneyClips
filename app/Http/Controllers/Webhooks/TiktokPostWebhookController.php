<?php

declare(strict_types=1);

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Http\Requests\Webhooks\TiktokPostWebhookRequest;
use App\Http\Resources\StatusResource;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\YoutubeShort;
use App\Services\API\Discord\DiscordNotifierService;
use Illuminate\Support\Facades\Log;

final class TiktokPostWebhookController extends Controller
{
    private const array FINISHED_STATUSES = ['completed', 'dry-run', 'restricted'];

    public function __invoke(TiktokPostWebhookRequest $request, DiscordNotifierService $discord): StatusResource
    {
        $post = SocialPost::query()
            ->where('platform', 'tiktok')
            ->where('uuid', $request->jobId())
            ->first();

        if (! $post instanceof SocialPost) {
            return new StatusResource('unknown-job', 404);
        }

        $claimed = SocialPost::query()
            ->whereKey($post->id)
            ->whereNotIn('status', self::FINISHED_STATUSES)
            ->update([
                'status' => $request->status(),
                'error' => $request->error() ?? $request->detail(),
                'posted_at' => in_array($request->status(), ['completed', 'dry-run'], true) ? now() : null,
            ]);

        if ($claimed !== 1) {
            return new StatusResource('already-finished');
        }

        $this->notifyOutcome($post->refresh(), $request, $discord);
        $this->syncAccount($request, $discord);

        return new StatusResource('ok');
    }

    private function notifyOutcome(SocialPost $post, TiktokPostWebhookRequest $request, DiscordNotifierService $discord): void
    {
        $title = $post->title ?? $post->youtube_id ?? $post->uuid;

        match ($request->status()) {
            'completed' => $this->markPosted($post, $title, $discord),
            'dry-run' => Log::channel('daily')->info('[INFO][AutoPost][TikTok] DRY_RUN — post simulado.', ['job_id' => $post->uuid]),
            'restricted' => $discord->warning(
                '⚠️ TikTok restringiu o post',
                $title.PHP_EOL.
                'Modal de moderação detectado — o vídeo não volta pro sorteio.'.PHP_EOL.
                'Detalhe: '.($request->detail() ?? $request->error() ?? '—'),
            ),
            default => $discord->error(
                '❌ Falha ao postar no TikTok',
                $title.PHP_EOL.($request->error() ?? 'O uploader não informou o motivo.'),
            ),
        };
    }

    private function markPosted(SocialPost $post, string $title, DiscordNotifierService $discord): void
    {
        YoutubeShort::query()
            ->where('youtube_id', $post->youtube_id)
            ->update(['posted_tiktok_at' => now()]);

        Log::channel('daily')->info('[INFO][AutoPost][TikTok] Short postado.', ['job_id' => $post->uuid]);
        $discord->success('✅ Short postado no TikTok', $title);
    }

    /**
     * Reflete o resultado da sessão na conta que originou o post (account_id
     * ecoado pelo uploader; fallback: conta ativa mais recente): cookies
     * renovados capturados pós-upload + session_status (invalid faz o
     * TiktokPosterService pular os próximos slots até renovar em /contas).
     */
    private function syncAccount(TiktokPostWebhookRequest $request, DiscordNotifierService $discord): void
    {
        $sessionStatus = $request->sessionStatus();
        $cookies = $request->refreshedCookies();

        if ($sessionStatus === null && $cookies === []) {
            return;
        }

        $accountId = $request->accountId();

        $account = SocialAccount::query()
            ->where('platform', 'tiktok')
            ->when($accountId !== null, fn ($q) => $q->whereKey($accountId))
            ->when($accountId === null, fn ($q) => $q->where('is_active', true)->latest('id'))
            ->first();

        if (! $account instanceof SocialAccount) {
            return;
        }

        if ($cookies !== []) {
            $account->cookies = $cookies;
        }

        if ($sessionStatus === SocialAccount::SESSION_VALID) {
            $account->session_status = SocialAccount::SESSION_VALID;
            $account->cookies_last_validated_at = now();
        }

        if ($sessionStatus === SocialAccount::SESSION_INVALID && $account->session_status !== SocialAccount::SESSION_INVALID) {
            $account->session_status = SocialAccount::SESSION_INVALID;
            $discord->error(
                '🔒 Sessão TikTok inválida',
                'O uploader recusou os cookies da conta.'.PHP_EOL.
                'Renove as credenciais em /contas antes do próximo slot.',
            );
        }

        $account->save();
    }
}
