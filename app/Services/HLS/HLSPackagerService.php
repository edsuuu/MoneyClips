<?php

declare(strict_types=1);

namespace App\Services\HLS;

use App\Models\Video;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

final readonly class HLSPackagerService
{
    /**
     * O desfecho chega por webhook chaveado pelo uuid do vídeo (o serviço ecoa
     * `video_uuid`) — não guardamos id de job. O Laravel manda TODAS as chaves
     * de destino; o serviço só escreve onde mandaram.
     */
    public function startPackaging(Video $video): void
    {
        $response = $this->client()->post('/package', [
            'video_uuid' => $video->uuid,
            'video_key' => $video->originalPath(),
            'hls_prefix' => $video->hlsPrefix(),
            'poster_key' => $video->posterPath(),
            'audio_key' => $video->audioPath(),
            'storyboard_key' => $video->storyboardPath(),
            'webhook_url' => (string) config('services.hls.webhook_url'),
        ]);

        if (! $response->successful()) {
            throw new RuntimeException(sprintf(
                'Serviço de HLS respondeu %d: %s',
                $response->status(),
                Str::limit($response->body(), 300),
            ));
        }
    }

    private function client(): PendingRequest
    {
        $token = (string) config('services.hls.api_token');

        return Http::baseUrl(mb_rtrim((string) config('services.hls.base_url'), '/'))
            ->connectTimeout(10)
            ->timeout((int) config('services.hls.timeout', 60))
            ->when($token !== '', fn (PendingRequest $request): PendingRequest => $request->withToken($token));
    }
}
