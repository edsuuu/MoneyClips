<?php

declare(strict_types=1);

namespace App\Services\DownloadShorts;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class DownloadShortsService
{
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
            ?: url('/api/webhook/download-youtube');
    }
}
