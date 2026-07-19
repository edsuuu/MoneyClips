<?php

declare(strict_types=1);

namespace App\Services\HLS;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Client do microserviço de HLS (porta 8795): empacota o vídeo em ABR
 * (360p/720p/1080p, fMP4/CMAF) e devolve o desfecho por webhook.
 *
 * Diferente dos outros clients, aqui trafegam CHAVES e não bytes: um vídeo de
 * 3GB vira milhares de segmentos, então o serviço lê a fonte e escreve a saída
 * direto no MinIO. É a segunda exceção à regra "só o Laravel toca o S3" (a
 * primeira é o download-shorts) — o serviço usa credencial própria, restrita a
 * leitura em `uploads/*` e escrita em `hls/*`.
 */
final readonly class HLSPackagerService
{
    public function startPackaging(string $videoKey, string $outputPrefix): string
    {
        $response = $this->client()->post('/package', [
            'video_key' => $videoKey,
            'output_prefix' => $outputPrefix,
            'webhook_url' => (string) config('services.hls.webhook_url'),
        ]);

        if (! $response->successful()) {
            throw new RuntimeException(sprintf(
                'Serviço de HLS respondeu %d: %s',
                $response->status(),
                Str::limit($response->body(), 300),
            ));
        }

        $uuid = $response->json('uuid');
        throw_unless(is_string($uuid) && $uuid !== '', RuntimeException::class, 'Serviço de HLS não retornou o uuid do job.');

        return $uuid;
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
