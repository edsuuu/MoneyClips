<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\YoutubeShort;
use App\Services\DiscordNotifier;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Log;

/**
 * Webhook do microserviço tiktok-uploader. Recebe o resultado final do upload
 * e cuida de 3 coisas:
 *  1. Atualiza o ledger (social_posts) + marca posted_tiktok_at no estoque.
 *  2. Se vierem `refreshed_cookies`, salva no social_accounts (refresh de
 *     sessão capturado pelo Playwright após o post).
 *  3. Reflete `session_status` na conta — `invalid` dispara alerta Discord
 *     pedindo intervenção manual (revisar a conta em /contas).
 */
final class TiktokPostCallbackController extends Controller
{
    public function __construct(private readonly DiscordNotifier $discord) {}

    public function __invoke(Request $request): JsonResponse
    {
        /** @var array{job_id: string, video_id: string, status: string, title?: string|null, error?: string|null, finished_at?: string|null, refreshed_cookies?: list<array<string, mixed>>|null, session_status?: string|null} $validated */
        $validated = $request->validate([
            'job_id' => ['required', 'string'],
            'video_id' => ['required', 'string'],
            'status' => ['required', 'string', 'in:completed,dry-run,restricted,failed'],
            'session_valid' => ['sometimes', 'boolean'],
            'login_failed' => ['sometimes', 'boolean'],
            'title' => ['nullable', 'string'],
            'error' => ['nullable', 'string'],
            'finished_at' => ['nullable', 'date'],
            'refreshed_cookies' => ['nullable', 'array'],
            'session_status' => ['nullable', 'string', 'in:valid,invalid,unknown'],
        ]);

        $status = $validated['status'];
        $videoId = $validated['video_id'];
        $title = (string) ($validated['title'] ?? '') ?: null;
        $error = (string) ($validated['error'] ?? '') ?: null;
        $finishedAtValue = (string) ($validated['finished_at'] ?? '');
        $finishedAt = CarbonImmutable::instance(
            $finishedAtValue !== '' ? Date::parse($finishedAtValue) : Date::now(),
        );

        Log::info('[TiktokCallback] webhook recebido.', [
            'job_id' => $validated['job_id'],
            'video_id' => $videoId,
            'status' => $status,
            'session_status' => $validated['session_status'] ?? null,
            'refreshed_cookies_count' => isset($validated['refreshed_cookies']) ? count($validated['refreshed_cookies']) : 0,
            'title' => $title,
        ]);

        SocialPost::query()->updateOrCreate(['uuid' => $validated['job_id']], [
            'platform' => SocialPost::PLATFORM_TIKTOK,
            'youtube_id' => $videoId,
            'video_key' => sprintf('shorts/%s.mp4', $videoId),
            'title' => $title,
            'status' => $status,
            'error' => $error,
            'posted_at' => $status === 'completed' ? $finishedAt : null,
        ]);

        $this->applySessionUpdate(
            $validated['refreshed_cookies'] ?? null,
            $validated['session_status'] ?? null,
            $finishedAt,
        );

        if ($status === 'completed') {
            YoutubeShort::query()
                ->where('youtube_id', $videoId)
                ->whereNull('posted_tiktok_at')
                ->update(['posted_tiktok_at' => $finishedAt]);

            $this->discord->success(
                '🎵 Short postado no TikTok',
                $title ?? $videoId,
            );
        } elseif ($status === 'failed') {
            $this->discord->error(
                '❌ Falha ao postar Short no TikTok',
                'Video: '.($title ?? $videoId).PHP_EOL.'Erro: '.($error ?? 'sem detalhes'),
            );
        } elseif ($status === 'restricted') {
            $this->discord->warning(
                '⚠️ Short restringido no TikTok',
                'Video: '.($title ?? $videoId).PHP_EOL.'Motivo: '.($error ?? 'TikTok marcou o conteúdo como restrito.'),
            );
        }

        return response()->json(['ok' => true]);
    }

    /**
     * Atualiza a row da conta TikTok com os dados de sessão devolvidos pelo
     * microserviço. Mantém o último cookie válido, registra a hora da
     * validação e alerta no Discord quando a sessão expira.
     *
     * @param  list<array<string, mixed>>|null  $refreshedCookies
     */
    private function applySessionUpdate(?array $refreshedCookies, ?string $sessionStatus, CarbonImmutable $now): void
    {
        if ($refreshedCookies === null && $sessionStatus === null) {
            return;
        }

        $account = SocialAccount::query()
            ->where('platform', 'tiktok')
            ->where('is_active', true)
            ->latest('id')
            ->first();

        if (! $account instanceof SocialAccount) {
            Log::warning('[TiktokCallback] webhook trouxe dados de sessão mas não há social_account ativa.');

            return;
        }

        $changed = false;

        if ($refreshedCookies !== null && $refreshedCookies !== []) {
            $account->cookies = $refreshedCookies;
            $account->cookies_last_validated_at = $now;
            $changed = true;
        }

        if ($sessionStatus !== null) {
            $previous = $account->session_status;
            $account->session_status = $sessionStatus;
            if ($sessionStatus === SocialAccount::SESSION_VALID) {
                $account->cookies_last_validated_at = $now;
            }

            $changed = $changed || $previous !== $sessionStatus;

            if ($sessionStatus === SocialAccount::SESSION_INVALID && $previous !== SocialAccount::SESSION_INVALID) {
                $this->discord->error(
                    '🔒 Sessão do TikTok inválida',
                    'O uploader não conseguiu autenticar (cookies expiraram e re-login falhou).'.PHP_EOL.
                    'Revise a conta em /contas.',
                );
            }
        }

        if ($changed) {
            $account->save();
        }
    }
}
