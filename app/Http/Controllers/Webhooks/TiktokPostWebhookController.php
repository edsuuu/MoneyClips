<?php

declare(strict_types=1);

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\YoutubeShort;
use App\Services\Api\Discord\DiscordNotifierService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

final class TiktokPostWebhookController extends Controller
{
    private const array FINISHED_STATUSES = ['completed', 'dry-run', 'restricted'];

    public function __invoke(Request $request, DiscordNotifierService $discord): JsonResponse
    {
        /** @var array{job_id: string, status: string, detail?: string|null, error?: string|null, session_status?: string|null, refreshed_cookies?: array<array-key, mixed>|null, account_id?: string|null} $data */
        $data = $request->validate([
            'job_id' => ['required', 'string'],
            'status' => ['required', 'in:completed,dry-run,restricted,failed'],
            'detail' => ['nullable', 'string'],
            'error' => ['nullable', 'string'],
            'session_status' => ['nullable', 'in:valid,invalid,unknown'],
            'refreshed_cookies' => ['nullable', 'array'],
            'account_id' => ['nullable', 'string'],
        ]);

        $post = SocialPost::query()
            ->where('platform', 'tiktok')
            ->where('uuid', $data['job_id'])
            ->first();

        if (! $post instanceof SocialPost) {
            return response()->json(['status' => 'unknown-job'], 404);
        }

        $claimed = SocialPost::query()
            ->whereKey($post->id)
            ->whereNotIn('status', self::FINISHED_STATUSES)
            ->update([
                'status' => $data['status'],
                'error' => $data['error'] ?? $data['detail'] ?? null,
                'posted_at' => in_array($data['status'], ['completed', 'dry-run'], true) ? now() : null,
            ]);

        if ($claimed !== 1) {
            return response()->json(['status' => 'already-finished']);
        }

        $this->notifyOutcome($post->refresh(), $data, $discord);
        $this->syncAccount($data, $discord);

        return response()->json(['status' => 'ok']);
    }

    /**
     * @param  array{job_id: string, status: string, detail?: string|null, error?: string|null, session_status?: string|null, refreshed_cookies?: array<array-key, mixed>|null, account_id?: string|null}  $data
     */
    private function notifyOutcome(SocialPost $post, array $data, DiscordNotifierService $discord): void
    {
        $title = $post->title ?? $post->youtube_id ?? $post->uuid;

        match ($data['status']) {
            'completed' => $this->markPosted($post, $title, $discord),
            'dry-run' => Log::info('[AutoPost][TikTok] DRY_RUN — post simulado.', ['job_id' => $post->uuid]),
            'restricted' => $discord->warning(
                '⚠️ TikTok restringiu o post',
                $title.PHP_EOL.
                'Modal de moderação detectado — o vídeo não volta pro sorteio.'.PHP_EOL.
                'Detalhe: '.($data['detail'] ?? $data['error'] ?? '—'),
            ),
            default => $discord->error(
                '❌ Falha ao postar no TikTok',
                $title.PHP_EOL.($data['error'] ?? 'O uploader não informou o motivo.'),
            ),
        };
    }

    private function markPosted(SocialPost $post, string $title, DiscordNotifierService $discord): void
    {
        YoutubeShort::query()
            ->where('youtube_id', $post->youtube_id)
            ->update(['posted_tiktok_at' => now()]);

        Log::info('[AutoPost][TikTok] Short postado.', ['job_id' => $post->uuid]);
        $discord->success('✅ Short postado no TikTok', $title);
    }

    /**
     * Reflete o resultado da sessão na conta que originou o post (account_id
     * ecoado pelo uploader; fallback: conta ativa mais recente): cookies
     * renovados capturados pós-upload + session_status (invalid faz o
     * TiktokPosterService pular os próximos slots até renovar em /contas).
     *
     * @param  array{job_id: string, status: string, detail?: string|null, error?: string|null, session_status?: string|null, refreshed_cookies?: array<array-key, mixed>|null, account_id?: string|null}  $data
     */
    private function syncAccount(array $data, DiscordNotifierService $discord): void
    {
        $sessionStatus = $data['session_status'] ?? null;
        $cookies = $data['refreshed_cookies'] ?? null;

        if ($sessionStatus === null && ($cookies === null || $cookies === [])) {
            return;
        }

        $accountId = $data['account_id'] ?? null;

        $account = SocialAccount::query()
            ->where('platform', 'tiktok')
            ->when($accountId !== null && $accountId !== '', fn ($q) => $q->whereKey((int) $accountId))
            ->when($accountId === null || $accountId === '', fn ($q) => $q->where('is_active', true)->latest('id'))
            ->first();

        if (! $account instanceof SocialAccount) {
            return;
        }

        if (is_array($cookies) && $cookies !== []) {
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
