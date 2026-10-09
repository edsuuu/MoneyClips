<?php

declare(strict_types=1);

namespace App\Services\Posting;

use App\Enums\PostStatusEnum;
use App\Models\SocialPost;
use App\Services\API\Discord\DiscordNotifierService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Fecha um post em Posting com o desfecho do provider: o job (provider
 * síncrono) e os webhooks (provider assíncrono) passam por aqui.
 */
final readonly class PostCloserService
{
    public function __construct(private DiscordNotifierService $discord) {}

    /**
     * O UPDATE só vale se a linha ainda está em Posting: se o reaper já marcou
     * Failed (upload passou de stuck_minutes) ou o dono mexeu, a resposta
     * chegou tarde e não pode sobrescrever nada, só avisar alto.
     */
    public function close(SocialPost $post, PostResultData $result): bool
    {
        $label = sprintf('%s · %s (%s)', $post->youtubeShort->title ?? $post->youtubeShort->youtube_id, $post->socialAccount->name, $post->socialAccount->platform);

        $values = match ($result->status) {
            PostStatusEnum::Posting => ['external_id' => $result->externalId],
            PostStatusEnum::Failed => ['status' => PostStatusEnum::Failed, 'error' => $result->error],
            default => ['status' => PostStatusEnum::Published, 'url' => $result->url, 'privacy' => $result->privacy, 'posted_at' => now()],
        };

        $postedColumn = match ($post->socialAccount->platform) {
            'youtube' => 'posted_youtube_at',
            'tiktok' => 'posted_tiktok_at',
            default => null,
        };

        $closed = DB::transaction(function () use ($post, $result, $values, $postedColumn): bool {
            if (SocialPost::query()->whereKey($post->id)->where('status', PostStatusEnum::Posting)->update($values) !== 1) {
                return false;
            }

            if ($result->status === PostStatusEnum::Published && ! is_null($postedColumn)) {
                $post->youtubeShort->forceFill([$postedColumn => now()])->save();
            }

            return true;
        });

        if (! $closed) {
            $outcome = sprintf('%s %s', $result->status->label(), $result->url ?? $result->externalId ?? $result->error ?? '');
            Log::channel('daily')->warning('[WARN][Posting] Resposta do provider chegou com o post já fora de Posting.', ['post_id' => $post->id, 'outcome' => $outcome]);
            $this->discord->warning('⚠️ Resposta do provider chegou tarde', sprintf('%s%sO post já tinha sido fechado. Resultado: %s%sNÃO tente de novo sem conferir na plataforma.', $label, PHP_EOL, $outcome, PHP_EOL));

            return false;
        }

        if ($result->status === PostStatusEnum::Posting) {
            Log::channel('daily')->info('[INFO][Posting] Post aceito pelo provider, aguardando o webhook.', ['post_id' => $post->id, 'external_id' => $result->externalId]);

            return true;
        }

        if ($result->status === PostStatusEnum::Failed) {
            Log::channel('daily')->error('[ERRO][Posting] Post falhou.', ['post_id' => $post->id, 'error' => $result->error]);
            $this->discord->error('❌ Post falhou', $label.PHP_EOL.$result->error);

            return true;
        }

        Log::channel('daily')->info('[INFO][Posting] Post publicado.', ['post_id' => $post->id, 'url' => $result->url, 'privacy' => $result->privacy]);
        $this->discord->success('✅ Post publicado', $label, $result->url);

        return true;
    }
}
