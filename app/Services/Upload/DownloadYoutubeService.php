<?php

declare(strict_types=1);

namespace App\Services\Upload;

use App\Models\Video;
use App\Services\Upload\Data\YoutubeVideoMetadataData;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

final readonly class DownloadYoutubeService
{
    /**
     * @throws ConnectionException
     * @throws RuntimeException
     */
    public function fetchMetadata(string $url): ?YoutubeVideoMetadataData
    {
        $response = $this->client()->get('/videos/metadata', ['url' => $url]);

        if (in_array($response->status(), [400, 404], true)) {
            return null;
        }

        if (! $response->successful()) {
            throw new RuntimeException(sprintf(
                'Serviço download-youtube respondeu %d: %s',
                $response->status(),
                Str::limit($response->body(), 300),
            ));
        }

        /** @var array<string, mixed> $data */
        $data = $response->json();

        return YoutubeVideoMetadataData::fromResponse($data);
    }

    /**
     * O desfecho chega por webhook chaveado pelo uuid do vídeo (o serviço ecoa
     * `video_uuid`) — não guardamos id de job. O Laravel manda a chave EXATA de
     * destino no MinIO; o serviço só escreve onde mandaram.
     *
     * @throws ConnectionException
     * @throws RuntimeException
     */
    public function startDownload(Video $video, string $url): void
    {
        $response = $this->client()->post('/videos/download', [
            'url' => $url,
            'video_uuid' => $video->uuid,
            'video_key' => $video->originalPath(),
            'webhook_url' => (string) config('services.download_youtube.video_webhook_url'),
        ]);

        if (! $response->successful()) {
            throw new RuntimeException(sprintf(
                'Serviço download-youtube respondeu %d: %s',
                $response->status(),
                Str::limit($response->body(), 300),
            ));
        }
    }

    private function client(): PendingRequest
    {
        return Http::baseUrl(mb_rtrim((string) config('services.download_youtube.base_url'), '/'))
            ->connectTimeout(10)
            ->timeout((int) config('services.download_youtube.timeout', 30))
            ->acceptJson()
            ->asJson();
    }
}
