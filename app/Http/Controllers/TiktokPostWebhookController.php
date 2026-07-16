<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\YoutubeShort;
use App\Services\Discord\DiscordNotifierService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Webhook do microserviço tiktok-uploader (POST /api/tiktok-posts/webhook):
 * fecha o desfecho de um post enfileirado pelo TiktokPosterService. Payload
 * {job_id, status: completed|dry-run|restricted|failed, detail?, error?,
 * session_status?, refreshed_cookies?}. Correlaciona pelo uuid do ledger
 * (= job_id devolvido no 202) e atualiza a conta TikTok (cookies renovados
 * pós-upload + session_status — invalid curto-circuita os próximos posts).
 */
final class TiktokPostWebhookController extends Controller
{
    private const array FINISHED_STATUSES = ['completed', 'dry-run', 'restricted', 'failed'];

    public function __invoke(Request $request, DiscordNotifierService $discord): JsonResponse
    {
        /** @var array{job_id: string, status: string, detail?: string|null, error?: string|null, session_status?: string|null, refreshed_cookies?: array<array-key, mixed>|null} $data */
        $data = $request->validate([
            'job_id' => ['required', 'string'],
            'status' => ['required', 'in:completed,dry-run,restricted,failed'],
            'detail' => ['nullable', 'string'],
            'error' => ['nullable', 'string'],
            'session_status' => ['nullable', 'in:valid,invalid,unknown'],
            'refreshed_cookies' => ['nullable', 'array'],
        ]);

        $post = SocialPost::query()
            ->where('platform', 'tiktok')
            ->where('uuid', $data['job_id'])
            ->first();

        if (! $post instanceof SocialPost) {
            return response()->json(['status' => 'unknown-job'], 404);
        }

        $this->syncAccount($data, $discord);

        // Idempotência: retry do webhook depois do desfecho não refaz nada.
        if (in_array($post->status, self::FINISHED_STATUSES, true)) {
            return response()->json(['status' => 'already-finished']);
        }

        $this->settleLedger($post, $data, $discord);

        return response()->json(['status' => 'ok']);
    }

    /**
     * @param  array{job_id: string, status: string, detail?: string|null, error?: string|null, session_status?: string|null, refreshed_cookies?: array<array-key, mixed>|null}  $data
     */
    private function settleLedger(SocialPost $post, array $data, DiscordNotifierService $discord): void
    {
        $title = $post->title ?? $post->youtube_id ?? $post->uuid;

        $post->fill([
            'status' => $data['status'],
            'error' => $data['error'] ?? $data['detail'] ?? null,
            'posted_at' => in_array($data['status'], ['completed', 'dry-run'], true) ? now() : null,
        ])->save();

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
     * Reflete o resultado da sessão na conta ativa: cookies renovados que o
     * Playwright capturou pós-upload + session_status (invalid faz o
     * TiktokPosterService pular os próximos slots até renovar em /contas).
     *
     * @param  array{job_id: string, status: string, detail?: string|null, error?: string|null, session_status?: string|null, refreshed_cookies?: array<array-key, mixed>|null}  $data
     */
    private function syncAccount(array $data, DiscordNotifierService $discord): void
    {
        $sessionStatus = $data['session_status'] ?? null;
        $cookies = $data['refreshed_cookies'] ?? null;

        if ($sessionStatus === null && ($cookies === null || $cookies === [])) {
            return;
        }

        $account = SocialAccount::query()
            ->where('platform', 'tiktok')
            ->where('is_active', true)
            ->latest('id')
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
