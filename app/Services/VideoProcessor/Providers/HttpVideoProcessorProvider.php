<?php

declare(strict_types=1);

namespace App\Services\VideoProcessor\Providers;

use App\Services\VideoProcessor\Contracts\VideoProcessorProviderInterface;
use App\Services\VideoProcessor\Data\IngestVideoData;
use App\Services\VideoProcessor\Data\RecommendCutsData;
use App\Services\VideoProcessor\Data\RenderCutsData;
use App\Services\VideoProcessor\Data\SubtitleFullData;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Implementação HTTP do contrato. Aqui ficam APENAS as chamadas de rede;
 * toda a regra de negócio mora em VideoProcessorService.
 */
final class HttpVideoProcessorProvider implements VideoProcessorProviderInterface
{
    public function ingest(IngestVideoData $data): array
    {
        /** @var array<string, mixed> $res */
        $res = $this->readyClient()
            ->post('/videos/ingest', $data->toArray())
            ->throw()
            ->json();

        return $res;
    }

    public function subtitleFull(string $videoUuid, SubtitleFullData $data): array
    {
        /** @var array<string, mixed> $res */
        $res = $this->readyClient()
            ->post(sprintf('/videos/%s/subtitle-full', $videoUuid), $data->toArray())
            ->throw()
            ->json();

        return $res;
    }

    public function recommendCuts(string $videoUuid, RecommendCutsData $data): array
    {
        /** @var array<string, mixed> $res */
        $res = $this->readyClient()
            ->post(sprintf('/videos/%s/recommend-cuts', $videoUuid), $data->toArray())
            ->throw()
            ->json();

        return $res;
    }

    public function renderCuts(string $videoUuid, RenderCutsData $data): array
    {
        /** @var array<string, mixed> $res */
        $res = $this->readyClient()
            ->post(sprintf('/videos/%s/render-cuts', $videoUuid), $data->toArray())
            ->throw()
            ->json();

        return $res;
    }

    private function readyClient(): PendingRequest
    {
        $client = $this->client();
        $this->ensureHeartbeat($client);

        return $client;
    }

    private function client(): PendingRequest
    {
        $baseUrlStr = (string) (config('video-processor.base_url')) ?: 'http://127.0.0.1:8765';

        $timeout = config('video-processor.timeout', 120);
        $timeoutInt = is_int($timeout) || is_numeric($timeout) ? (int) $timeout : 120;

        $request = Http::baseUrl($baseUrlStr)
            ->timeout($timeoutInt)
            ->acceptJson()
            ->asJson();

        $token = config('video-processor.token');
        $tokenStr = is_string($token) ? $token : '';
        if ($tokenStr !== '') {
            return $request->withToken($tokenStr);
        }

        return $request;
    }

    private function ensureHeartbeat(PendingRequest $client): void
    {
        $timeout = config('video-processor.timeout', 120);
        $timeoutInt = is_int($timeout) || is_numeric($timeout) ? (int) $timeout : 120;
        $heartbeat = $client
            ->timeout(min($timeoutInt, 10))
            ->get('/heart');

        throw_unless($heartbeat->successful(), RuntimeException::class, 'Microservico de video indisponivel: /heart nao respondeu corretamente.');
    }
}
