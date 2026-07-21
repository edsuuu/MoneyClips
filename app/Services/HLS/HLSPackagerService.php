<?php

declare(strict_types=1);

namespace App\Services\HLS;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

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
