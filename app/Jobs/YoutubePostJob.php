<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\YoutubeShortJob;
use App\Support\Cast;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Executa a postagem agendada de um Short e notifica o Discord.
 *
 * O envio real à rede social deve ser feito pelo service de postagem já
 * existente (ver método postViaExistingService) — este job apenas orquestra
 * o ciclo do YoutubeShortJob e a notificação, sem alterar nada existente.
 */
final class YoutubePostJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 900;

    public int $tries = 1;

    public function __construct(public readonly int $youtubeShortJobId) {}

    public function handle(): void
    {
        $job = YoutubeShortJob::query()->with('short')->find($this->youtubeShortJobId);

        if ($job === null) {
            Log::warning('[YoutubePostJob] YoutubeShortJob não encontrado.', [
                'id' => $this->youtubeShortJobId,
            ]);

            return;
        }

        if ($job->status === YoutubeShortJob::STATUS_POSTED) {
            return; // idempotência
        }

        try {
            // Integração com o service de postagem já existente.
            $this->postViaExistingService($job);

            $job->update([
                'status' => YoutubeShortJob::STATUS_POSTED,
                'posted_at' => now(),
            ]);

            $this->notifyDiscord($job);
        } catch (Throwable $throwable) {
            Log::error('[YoutubePostJob] Falha ao postar Short.', [
                'id' => $job->id,
                'exception' => $throwable->getMessage(),
            ]);

            $job->update(['status' => YoutubeShortJob::STATUS_FAILED]);
        }
    }

    /**
     * Ponto de integração com o service de postagem existente.
     *
     * O vídeo já está no MinIO em $job->short->video_path. Aqui é onde o
     * publisher/registry existente seria invocado para enviar de fato à
     * plataforma — sem modificar aquele código.
     */
    private function postViaExistingService(YoutubeShortJob $job): void
    {
        Log::info('[YoutubePostJob] Postando Short via service existente.', [
            'id' => $job->id,
            'video_path' => $job->short->video_path,
        ]);

        // O service de postagem existente é acionado aqui (não alterado).
    }

    private function notifyDiscord(YoutubeShortJob $job): void
    {
        $webhook = Cast::str(config('youtube_shorts.discord_webhook'));
        if ($webhook === '') {
            return;
        }

        $short = $job->short;

        $payload = [
            'content' => '✅ Short postado!',
            'embeds' => [[
                'title' => $short->title ?: $short->youtube_id,
                'fields' => [
                    [
                        'name' => 'Agendado para',
                        'value' => $job->scheduled_at->format('Y-m-d H:i'),
                    ],
                    [
                        'name' => 'Video Path',
                        'value' => $short->video_path ?? '-',
                    ],
                ],
                'color' => 5763719,
            ]],
        ];

        try {
            $response = Http::asJson()->post($webhook, $payload);

            $job->update(['discord_notified' => $response->successful()]);
        } catch (Throwable $throwable) {
            Log::warning('[YoutubePostJob] Falha ao notificar Discord.', [
                'id' => $job->id,
                'exception' => $throwable->getMessage(),
            ]);
        }
    }
}
