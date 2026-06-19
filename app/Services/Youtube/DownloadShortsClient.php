<?php

declare(strict_types=1);

namespace App\Services\Youtube;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Cliente HTTP do microserviço Python download-shorts (porta 8770).
 *
 * Fire-and-forget: o Laravel envia channel_url + webhook_url e o microserviço
 * baixa os Shorts em pool de threads, postando cada item concluído no webhook.
 * Não há banco no microserviço — o registro dos Shorts vive em youtube_shorts
 * aqui no Laravel.
 */
final class DownloadShortsClient
{
    /**
     * Dispara o download dos Shorts do canal. Retorna a quantidade de Shorts
     * listados (resposta síncrona; o download em si roda em background no
     * microserviço, com webhook por item).
     */
    public function createDownload(string $channelUrl): int
    {
        /** @var array<string, mixed> $response */
        $response = $this->client()
            ->post('/shorts/download', [
                'channel_url' => $channelUrl,
                'webhook_url' => $this->webhookUrl(),
            ])
            ->throw()
            ->json();

        throw_unless(array_key_exists('count', $response), RuntimeException::class, 'Microserviço download-shorts não retornou count.');

        return (int) ($response['count']);
    }

    public function health(): bool
    {
        try {
            return $this->client()
                ->timeout(5)
                ->get('/health')
                ->successful();
        } catch (Throwable) {
            return false;
        }
    }

    private function client(): PendingRequest
    {
        $baseUrl = (string) (config('services.download_youtube.base_url')) ?: 'http://127.0.0.1:8770';
        $timeout = (int) (config('services.download_youtube.timeout')) ?: 30;

        return Http::baseUrl($baseUrl)
            ->timeout($timeout)
            ->acceptJson()
            ->asJson();
    }

    private function webhookUrl(): string
    {
        return (string) (config('services.download_youtube.webhook_url'))
            ?: url('/api/download-youtube/webhook');
    }
}
