<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\PostStatusEnum;
use App\Jobs\Concerns\TransfersStorageFiles;
use App\Models\SocialPost;
use App\Services\API\Discord\DiscordNotifierService;
use App\Services\Posting\PostCloserService;
use App\Services\Posting\PostResultData;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
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
    public function handle(PostCloserService $closer): void
    {
        $post = SocialPost::query()->with(['youtubeShort', 'socialAccount'])->find($this->postId);

        if (is_null($post) || $post->status !== PostStatusEnum::Posting) {
            Log::channel('daily')->warning('[WARN][Posting] Post sumiu ou saiu de Posting antes do envio — ignorando.', ['post_id' => $this->postId]);

            return;
        }

        $key = $post->youtubeShort->postableVideoPath();
        if ($key === '' || ! Storage::disk('s3')->exists($key)) {
            $closer->close($post, PostResultData::failed(sprintf('Vídeo não encontrado no MinIO: "%s".', $key)));

            return;
        }

        $tmp = $this->pullToTemp($key, 'post-');

        try {
            $result = $post->socialAccount->provider->service()->post($post, $tmp);
        } finally {
            @unlink($tmp);
        }

        $closer->close($post, $result);
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
}
