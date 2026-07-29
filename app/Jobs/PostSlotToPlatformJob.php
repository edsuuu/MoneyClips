<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\ScheduleSlot;
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

final class PostSlotToPlatformJob implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public int $tries = 1;

    public int $timeout = 1800;

    public function __construct(
        public ?int $slotId,
        public string $platform,
        public ?int $shortId = null,
    ) {
        $this->onQueue('posting');
    }

    public function handle(PosterRegistryService $registry, DiscordNotifierService $discord): void
    {
        $slot = $this->slotId !== null
            ? ScheduleSlot::query()->with('youtubeShort')->find($this->slotId)
            : null;
        $short = $slot->youtubeShort
            ?? ($this->shortId !== null ? YoutubeShort::query()->find($this->shortId) : null);

        if (! $short instanceof YoutubeShort) {
            Log::channel('daily')->warning('[WARN][AutoPost] Slot/vídeo sumiu antes do post — ignorando.', ['slot_id' => $this->slotId, 'short_id' => $this->shortId]);

            return;
        }

        $task = PostTaskData::fromShort($short, $slot);
        $ledger = $this->ledger($slot, $task);

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
            ->when($this->slotId !== null, fn ($q) => $q->where('schedule_slot_id', $this->slotId))
            ->when($this->slotId === null, fn ($q) => $q
                ->whereIn('youtube_id', YoutubeShort::query()->whereKey($this->shortId)->select('youtube_id'))
                ->where('status', 'processing'))
            ->update(['status' => 'failed', 'error' => $error]);

        Log::channel('daily')->error('[ERRO][AutoPost] Job de postagem falhou.', [
            'slot_id' => $this->slotId,
            'platform' => $this->platform,
            'exception' => $exception,
            'message' => $error,
            'file' => $exception?->getFile(),
            'line' => $exception?->getLine(),
        ]);

        resolve(DiscordNotifierService::class)->error(
            '❌ Job de postagem falhou',
            sprintf(
                '%s · %s%s%s',
                $this->slotId !== null ? 'Slot #'.$this->slotId : 'Post instantâneo',
                $this->platform,
                PHP_EOL,
                $error,
            ),
        );
    }

    private function ledger(?ScheduleSlot $slot, PostTaskData $task): SocialPost
    {
        $ledger = $slot instanceof ScheduleSlot
            ? SocialPost::query()->firstOrCreate(
                ['schedule_slot_id' => $slot->id, 'platform' => $this->platform],
                [
                    'uuid' => (string) Str::uuid(),
                    'youtube_id' => $task->short->youtube_id,
                    'requested_at' => now(),
                ],
            )
            : SocialPost::query()->create([
                'platform' => $this->platform,
                'uuid' => (string) Str::uuid(),
                'youtube_id' => $task->short->youtube_id,
                'requested_at' => now(),
            ]);

        $ledger->fill([
            'status' => 'processing',
            'error' => null,
            'title' => $task->title,
            'hashtags' => $task->hashtags,
            'video_key' => $task->videoPath,
            'started_at' => now(),
        ])->save();

        return $ledger;
    }
}
