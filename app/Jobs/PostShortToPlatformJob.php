<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\SocialPost;
use App\Models\YoutubeShort;
use App\Services\API\Discord\DiscordNotifierService;
use App\Services\AutoPost\PosterRegistryService;
use App\Services\AutoPost\PostTaskData;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

final class PostShortToPlatformJob implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public int $tries = 1;

    public int $timeout = 1800;

    public function __construct(
        public string $platform,
        public int $shortId,
    ) {
        $this->onQueue('posting');
    }

    public function handle(PosterRegistryService $registry, DiscordNotifierService $discord): void
    {
        $short = YoutubeShort::query()->find($this->shortId);

        if (! $short instanceof YoutubeShort) {
            Log::warning('[AutoPost] Vídeo sumiu antes do post — ignorando.', ['short_id' => $this->shortId]);

            return;
        }

        $task = PostTaskData::fromShort($short);
        $ledger = $this->ledger($task);

        // Guarda: linha "fantasma" (video_path sem objeto no MinIO) falha
        // com erro claro em vez de estourar dentro do poster.
        if ($task->videoPath === '' || ! Storage::disk('s3')->exists($task->videoPath)) {
            $error = sprintf('Vídeo não encontrado no MinIO: "%s".', $task->videoPath);
            $ledger->fill(['status' => 'failed', 'error' => $error])->save();
            $discord->error('❌ Post abortado — arquivo ausente', $task->title.PHP_EOL.$error);

            return;
        }

        $result = $registry->for($this->platform)->post($task);

        $ledger->fill([
            'status' => $result->ledgerStatus(),
            'error' => $result->error,
            'posted_at' => $result->succeeded() ? now() : null,
        ]);

        // Poster assíncrono (TikTok não-oficial): o uuid vira o job_id do
        // microserviço — é assim que o TiktokPostWebhookController acha esta
        // linha pra fechar o desfecho que chega no callback.
        if ($result->isQueued() && $result->externalId !== null) {
            $ledger->uuid = $result->externalId;
        }

        $ledger->save();

        // Colunas de conveniência do estoque (só existem pra YT/TT; as demais
        // plataformas vivem só no ledger).
        if ($result->succeeded() && in_array($this->platform, ['youtube', 'tiktok'], true)) {
            $short->forceFill(['posted_'.$this->platform.'_at' => now()])->save();
        }
    }

    public function failed(?Throwable $exception): void
    {
        $error = $exception?->getMessage() ?? 'Falha desconhecida no job de postagem.';

        SocialPost::query()
            ->where('platform', $this->platform)
            ->whereIn('youtube_id', YoutubeShort::query()->whereKey($this->shortId)->select('youtube_id'))
            ->where('status', 'processing')
            ->update(['status' => 'failed', 'error' => $error]);

        Log::error('[AutoPost] Job de postagem falhou.', [
            'short_id' => $this->shortId,
            'platform' => $this->platform,
            'error' => $error,
        ]);

        resolve(DiscordNotifierService::class)->error(
            '❌ Job de postagem falhou',
            sprintf('Short #%d · %s%s%s', $this->shortId, $this->platform, PHP_EOL, $error),
        );
    }

    private function ledger(PostTaskData $task): SocialPost
    {
        return SocialPost::query()->create([
            'platform' => $this->platform,
            'uuid' => (string) Str::uuid(),
            'youtube_id' => $task->short->youtube_id,
            'requested_at' => now(),
            'status' => 'processing',
            'title' => $task->title,
            'hashtags' => $task->hashtags,
            'video_key' => $task->videoPath,
            'started_at' => now(),
        ]);
    }
}
