<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\PostStatusEnum;
use App\Jobs\Concerns\TransfersStorageFiles;
use App\Models\SocialPost;
use App\Services\API\Discord\DiscordNotifierService;
use App\Services\Posting\PostResultData;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Uma tentativa só ($tries = 1): falha no meio do upload não diz se o vídeo
 * saiu na plataforma, e repostar às cegas duplica o post. Tentar de novo é
 * botão do dono.
 */
final class PublishPostJob implements ShouldQueue
{
    use Dispatchable;
    use Queueable;
    use TransfersStorageFiles;

    public int $tries = 1;

    public int $timeout = 1800;

    public function __construct(public int $postId)
    {
        $this->onQueue('posting');
    }

    /**
     * @throws Throwable
     */
    public function handle(DiscordNotifierService $discord): void
    {
        $post = SocialPost::query()->with(['youtubeShort', 'socialAccount'])->find($this->postId);

        if (is_null($post) || $post->status !== PostStatusEnum::Posting) {
            Log::channel('daily')->warning('[WARN][Posting] Post sumiu ou saiu de Posting antes do envio — ignorando.', ['post_id' => $this->postId]);

            return;
        }

        $key = $post->youtubeShort->postableVideoPath();
        if ($key === '' || ! Storage::disk('s3')->exists($key)) {
            $this->close($post, PostResultData::failed(sprintf('Vídeo não encontrado no MinIO: "%s".', $key)), $discord);

            return;
        }

        $tmp = $this->pullToTemp($key, 'post-');

        try {
            $result = $post->socialAccount->provider->service()->post($post, $tmp);
        } finally {
            @unlink($tmp);
        }

        $this->close($post, $result, $discord);
    }

    public function failed(?Throwable $exception): void
    {
        $error = sprintf(
            '%s Pode ter saído: confira na plataforma antes de tentar de novo.',
            $exception?->getMessage() ?? 'Falha desconhecida no job de postagem.',
        );

        SocialPost::query()->whereKey($this->postId)->where('status', PostStatusEnum::Posting)
            ->update(['status' => PostStatusEnum::Failed, 'error' => $error]);

        Log::channel('daily')->error('[ERRO][Posting] Job de postagem falhou.', [
            'post_id' => $this->postId,
            'exception' => $exception,
            'message' => $error,
            'file' => $exception?->getFile(),
            'line' => $exception?->getLine(),
        ]);

        resolve(DiscordNotifierService::class)->error('❌ Job de postagem falhou', sprintf('Post #%d%s%s', $this->postId, PHP_EOL, $error));
    }

    /**
     * O UPDATE só vale se a linha ainda está em Posting: se o reaper já marcou
     * Failed (upload passou de stuck_minutes) ou o dono mexeu, a resposta
     * chegou tarde e não pode sobrescrever nada, só avisar alto.
     */
    private function close(SocialPost $post, PostResultData $result, DiscordNotifierService $discord): void
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
            $discord->warning('⚠️ Resposta do provider chegou tarde', sprintf('%s%sO post já tinha sido fechado. Resultado: %s%sNÃO tente de novo sem conferir na plataforma.', $label, PHP_EOL, $outcome, PHP_EOL));

            return;
        }

        if ($result->status === PostStatusEnum::Posting) {
            Log::channel('daily')->info('[INFO][Posting] Post aceito pelo provider, aguardando o webhook.', ['post_id' => $post->id, 'external_id' => $result->externalId]);

            return;
        }

        if ($result->status === PostStatusEnum::Failed) {
            Log::channel('daily')->error('[ERRO][Posting] Post falhou.', ['post_id' => $post->id, 'error' => $result->error]);
            $discord->error('❌ Post falhou', $label.PHP_EOL.$result->error);

            return;
        }

        Log::channel('daily')->info('[INFO][Posting] Post publicado.', ['post_id' => $post->id, 'url' => $result->url, 'privacy' => $result->privacy]);
        $discord->success('✅ Post publicado', $label, $result->url);
    }
}
