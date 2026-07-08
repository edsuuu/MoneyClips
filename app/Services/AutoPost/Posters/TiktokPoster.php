<?php

declare(strict_types=1);

namespace App\Services\AutoPost\Posters;

use App\Models\SocialAccount;
use App\Models\User;
use App\Models\YoutubeShort;
use App\Services\DiscordNotifier;
use App\Services\TikTok\TiktokPostService;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Enfileira a postagem no TikTok via microserviço uploader (ASSÍNCRONO).
 *
 * O sucesso real é confirmado pelo webhook em /api/tiktok-posts/callback —
 * aqui só registramos que o job foi enfileirado e devolvemos PosterResult::queued.
 */
final readonly class TiktokPoster implements PosterContract
{
    public function __construct(
        private TiktokPostService $tiktok,
        private DiscordNotifier $discord,
    ) {}

    public function platform(): string
    {
        return 'tiktok';
    }

    public function isEnabled(): bool
    {
        // Cron roda sem usuário autenticado — usamos o user mais antigo (admin)
        // como fonte de verdade do toggle. Cada user mexe no seu pela /agenda.
        $user = User::query()->orderBy('id')->first();

        return $user instanceof User ? $user->auto_post_tiktok_enabled : true;
    }

    public function post(YoutubeShort $short): PosterResult
    {
        // Curto-circuito: se a conta TikTok está marcada como inválida,
        // não tenta postar pra evitar acumular falhas e flag de spam.
        // O operador precisa revisar a conta em /contas.
        $account = SocialAccount::query()
            ->where('platform', 'tiktok')
            ->where('is_active', true)
            ->latest('id')
            ->first();

        if ($account instanceof SocialAccount && $account->session_status === SocialAccount::SESSION_INVALID) {
            Log::warning('[AutoPost][TikTok] Sessão inválida — pulando disparo.', ['id' => $short->id]);

            return PosterResult::failed($this->platform(), 'Sessão inválida — revise a conta em /contas');
        }

        Log::info('[AutoPost][TikTok] Despachando Short.', [
            'id' => $short->id,
            'youtube_id' => $short->youtube_id,
            'title' => $short->title,
            'hashtags' => $short->hashtags,
            'channel_url' => $short->channel_url,
            'video_path' => $short->video_path,
            'downloaded_at' => $short->downloaded_at?->toIso8601String(),
            'dispatched_at' => $short->dispatched_at?->toIso8601String(),
        ]);

        try {
            $jobId = $this->tiktok->queuePost(
                $short->youtube_id,
                $short->title ?? $short->youtube_id,
                $short->hashtags ?? [],
                $short->video_path,
            );

            Log::info('[AutoPost][TikTok] Short enfileirado no uploader.', [
                'id' => $short->id,
                'job_id' => $jobId,
                'video_path' => $short->video_path,
            ]);

            return PosterResult::queued($this->platform());
        } catch (Throwable $throwable) {
            Log::error('[AutoPost][TikTok] Falha ao enfileirar.', [
                'id' => $short->id,
                'error' => $throwable->getMessage(),
            ]);
            $this->discord->error(
                '❌ Falha ao enfileirar Short no TikTok',
                ($short->title ?? $short->youtube_id).PHP_EOL.$throwable->getMessage(),
            );

            return PosterResult::failed($this->platform(), $throwable->getMessage());
        }
    }
}
