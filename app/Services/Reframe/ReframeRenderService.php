<?php

declare(strict_types=1);

namespace App\Services\Reframe;

use App\Models\ReframeEdit;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

final readonly class ReframeRenderService
{
    /**
     * O desfecho chega por webhook chaveado pelo uuid da edição (o serviço ecoa
     * `edit_uuid`). O Laravel manda as chaves de destino — o serviço só escreve
     * onde mandaram. O transcript vai no corpo quando as legendas estão ligadas
     * e a transcrição do corte está pronta.
     *
     * @param  array<mixed>|null  $transcript
     *
     * @throws ConnectionException
     */
    public function startRender(ReframeEdit $edit, ?array $transcript): void
    {
        $response = $this->client()->post('/reframe', [
            'edit_uuid' => $edit->uuid,
            'source_key' => $edit->source_path,
            'output_key' => $edit->renderOutputPath(),
            'source' => $edit->source_meta,
            'keyframes' => $edit->keyframes,
            'settings' => $edit->settings ?? [],
            'transcript' => $transcript,
            'webhook_url' => (string) config('services.reframe.webhook_url'),
        ]);

        if (! $response->successful()) {
            throw new RuntimeException(sprintf(
                'Serviço de reframe respondeu %d: %s',
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
