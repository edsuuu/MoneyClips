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
            return;
        }

        // Só processa posts que o dispatcher marcou como publishing (evita corrida/duplicação).
        if ($post->status !== ScheduledPost::STATUS_PUBLISHING) {
            return;
        }

        $publisher = $registry->for($post->platform);
        if (! $publisher instanceof SocialPublisher) {
            $this->markFailed($post, 'Plataforma não suportada: '.$post->platform);

            return;
        }

        $post->increment('attempts');
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

            return;
        }

        $this->handleFailure($post, $result->message, $result->context);
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
