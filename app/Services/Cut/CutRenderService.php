<?php

declare(strict_types=1);

namespace App\Services\Cut;

use App\Models\VideoCut;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

final readonly class CutRenderService
{
    /**
     * O desfecho chega por webhook chaveado pelo uuid do corte (o serviço ecoa
     * `cut_uuid`). O Laravel manda as chaves de destino; o serviço só escreve
     * onde mandaram.
     *
     * @throws ConnectionException
     */
    public function startRender(VideoCut $cut): void
    {
        $response = $this->client()->post('/cut', [
            'cut_uuid' => $cut->uuid,
            'video_key' => $cut->video->originalPath(),
            'start_seconds' => $cut->start_seconds,
            'end_seconds' => $cut->end_seconds,
            'clip_key' => $cut->clipPath(),
            'audio_key' => $cut->clipAudioPath(),
            'webhook_url' => (string) config('services.cut.webhook_url'),
        ]);

        if (! $response->successful()) {
            throw new RuntimeException(sprintf(
                'Serviço de corte respondeu %d: %s',
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
