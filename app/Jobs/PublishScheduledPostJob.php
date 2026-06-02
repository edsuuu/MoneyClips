<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\ScheduledPost;
use App\Models\SocialPostLog;
use App\Services\SocialPublishing\Contracts\SocialPublisher;
use App\Services\SocialPublishing\SocialPublisherRegistry;
use App\Support\Cast;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Publica de fato um ScheduledPost na plataforma. Roda na fila; cada execução
 * trata um único post e registra logs detalhados para o dashboard.
 */
final class PublishScheduledPostJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(public readonly int $scheduledPostId) {}

    public function handle(SocialPublisherRegistry $registry): void
    {
        $post = ScheduledPost::query()->with(['cut.files', 'video.files', 'account'])->find($this->scheduledPostId);

        if (! $post instanceof ScheduledPost) {
            Log::warning('[PublishScheduledPostJob] ScheduledPost não encontrado.', ['scheduled_post_id' => $this->scheduledPostId]);

            return;
        }

        $lock = Cache::lock('scheduled-post:'.$post->id, $this->timeout);

        if (! $lock->get()) {
            Log::warning('[PublishScheduledPostJob] Lock não adquirido; outro processo já está publicando.', ['scheduled_post_id' => $post->id]);

            return;
        }

        try {
            $post->refresh();

            if ($post->status === ScheduledPost::STATUS_SCHEDULED) {
                $post->update(['status' => ScheduledPost::STATUS_PUBLISHING]);
            }

            // Só processa posts que o dispatcher marcou como publishing (evita corrida/duplicação).
            if ($post->status !== ScheduledPost::STATUS_PUBLISHING) {
                Log::warning('[PublishScheduledPostJob] Post ignorado: status não é publishing.', [
                    'scheduled_post_id' => $post->id,
                    'status' => $post->status,
                ]);

                return;
            }

            Log::info('[PublishScheduledPostJob] Iniciando publicação.', [
                'scheduled_post_id' => $post->id,
                'platform' => $post->platform,
                'attempts' => $post->attempts,
            ]);

            $publisher = $registry->for($post->platform);
            if (! $publisher instanceof SocialPublisher) {
                Log::error('[PublishScheduledPostJob] Plataforma não suportada.', [
                    'scheduled_post_id' => $post->id,
                    'platform' => $post->platform,
                ]);
                $this->markFailed($post, 'Plataforma não suportada: '.$post->platform);

                return;
            }

            $post->increment('attempts');
            $post->refresh();
            $post->log(SocialPostLog::LEVEL_INFO, sprintf('Publicando em %s (tentativa %s).', $publisher->label(), $post->attempts));

            $result = $publisher->publish($post);

            if ($result->success) {
                $post->update([
                    'status' => ScheduledPost::STATUS_POSTED,
                    'external_post_id' => $result->externalId,
                    'external_url' => $result->url,
                    'error_message' => null,
                    'posted_at' => now(),
                    'payload' => $result->context ?: $post->payload,
                ]);
                $post->log(SocialPostLog::LEVEL_INFO, $result->message, $result->context);
                Log::info('[PublishScheduledPostJob] Publicado com sucesso.', [
                    'scheduled_post_id' => $post->id,
                    'platform' => $post->platform,
                    'external_id' => $result->externalId,
                    'message' => $result->message,
                    'context' => $result->context,
                ]);

                return;
            }

            Log::error('[PublishScheduledPostJob] Falha na publicação.', [
                'scheduled_post_id' => $post->id,
                'platform' => $post->platform,
                'message' => $result->message,
                'context' => $result->context,
            ]);
            $this->handleFailure($post, $result->message, $result->context);
        } finally {
            $lock->release();
        }
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function handleFailure(ScheduledPost $post, string $message, array $context): void
    {
        $maxAttempts = Cast::int(config('social-publishing.max_attempts', 3));

        if ($post->attempts >= $maxAttempts) {
            $this->markFailed($post, $message, $context);

            return;
        }

        // Reagenda para nova tentativa daqui a alguns minutos.
        $post->update([
            'status' => ScheduledPost::STATUS_SCHEDULED,
            'scheduled_for' => now()->addMinutes(5),
            'error_message' => $message,
        ]);
        $post->log(
            SocialPostLog::LEVEL_WARNING,
            sprintf('Falha (tentativa %s/%d); reagendado em 5 min: %s', $post->attempts, $maxAttempts, $message),
            $context,
        );
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function markFailed(ScheduledPost $post, string $message, array $context = []): void
    {
        $post->update([
            'status' => ScheduledPost::STATUS_FAILED,
            'error_message' => $message,
        ]);
        $post->log(SocialPostLog::LEVEL_ERROR, $message, $context);
    }
}
