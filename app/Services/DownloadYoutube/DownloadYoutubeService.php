<?php

declare(strict_types=1);

namespace App\Services\DownloadYoutube;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

final class DownloadYoutubeService
{
    public function createDownload(string $channelUrl): string
    {
        /** @var array<string, mixed> $response */
        $response = $this->client()
            ->post('/shorts/download', [
                'channel_url' => $channelUrl,
                'webhook_url' => $this->webhookUrl(),
                'dispatch_on_complete' => false,
            ])
            ->throw()
            ->json();

        $jobId = (string) ($response['job_id'] ?? '');

        throw_if($jobId === '', RuntimeException::class, 'Microserviço download-youtube não retornou job_id.');

        return $jobId;
    }

    /**
     * @return array<string, mixed>
     */
    public function getJobStatus(string $jobId): array
    {
        /** @var array<string, mixed> $response */
        $response = $this->client()
            ->get(sprintf('/shorts/download/%s', $jobId))
            ->throw()
            ->json();

        return $response;
    }

    /**
     * @return array{total: int, items: list<array<string, mixed>>}
     */
    public function listItems(int $limit, int $offset, string $status = 'completed'): array
    {
        /** @var array<string, mixed> $response */
        $response = $this->client()
            ->get('/shorts/items', [
                'status' => $status,
                'limit' => $limit,
                'offset' => $offset,
            ])
            ->throw()
            ->json();

        /** @var list<array<string, mixed>> $items */
        $items = array_values(array_filter((array) ($response['items'] ?? []), is_array(...)));

        return [
            'total' => (int) ($response['total'] ?? 0),
            'items' => $items,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function dispatchPending(int $batchSize = 100, bool $deleteAfterDispatch = true): array
    {
        /** @var array<string, mixed> $response */
        $response = $this->client()
            ->post('/shorts/dispatch', [
                'mode' => 'batch',
                'batch_size' => max(1, min($batchSize, 1000)),
                'webhook_url' => $this->webhookUrl(),
                'delete_after_dispatch' => $deleteAfterDispatch,
            ])
            ->throw()
            ->json();

        return $response;
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
        $baseUrl = (string) (config('microservices.download_youtube.base_url')) ?: 'http://127.0.0.1:8770';
        $timeout = (int) (config('microservices.download_youtube.timeout')) ?: 30;

        return Http::baseUrl($baseUrl)
            ->timeout($timeout)
            ->acceptJson()
            ->asJson();
    }

    private function webhookUrl(): string
    {
        return (string) (config('microservices.download_youtube.webhook_url'))
            ?: url('/api/download-youtube/webhook');
    }
}
