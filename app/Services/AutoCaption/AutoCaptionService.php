<?php

declare(strict_types=1);

namespace App\Services\AutoCaption;

use App\Enums\TemplateStyleEnum;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Client do microserviço AutoCaption (porta 8780): renderiza o template
 * (legenda karaokê + moldura com canal/@handle). O Laravel envia o binário
 * por multipart, o serviço processa assíncrono e avisa via webhook
 * (/api/autocaption/webhook); o output é baixado daqui e gravado no MinIO
 * pelo Laravel — o serviço não toca no S3.
 */
final readonly class AutoCaptionService
{
    /**
     * Sobe o vídeo e inicia o render. Retorna o uuid remoto do job.
     */
    public function createRender(
        string $sourcePath,
        TemplateStyleEnum $style,
        string $channelName,
        string $channelHandle,
    ): string {
        $stream = fopen($sourcePath, 'rb');
        throw_unless(is_resource($stream), RuntimeException::class, sprintf('Arquivo local não encontrado: %s', $sourcePath));

        try {
            $response = $this->client()
                ->attach('file', $stream, basename($sourcePath))
                ->post('/videos', [
                    'variants' => $style->variant(),
                    'caption_position' => $style->captionPosition(),
                    'channel_name' => $channelName,
                    'channel_handle' => $channelHandle,
                    'webhook_url' => (string) config('services.autocaption.webhook_url'),
                ]);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        if (! $response->successful()) {
            throw new RuntimeException(sprintf(
                'AutoCaption respondeu %d: %s',
                $response->status(),
                Str::limit($response->body(), 300),
            ));
        }

        $uuid = $response->json('uuid');
        throw_unless(is_string($uuid) && $uuid !== '', RuntimeException::class, 'AutoCaption não retornou o uuid do job.');

        return $uuid;
    }

    /** Baixa o variant renderizado para um arquivo local (sink). */
    public function downloadOutputTo(string $remoteId, TemplateStyleEnum $style, string $sinkPath): void
    {
        $response = $this->client()
            ->withOptions(['sink' => $sinkPath])
            ->get(sprintf('/videos/%s/output/%s', $remoteId, $style->variant()));

        if (! $response->successful()) {
            throw new RuntimeException(sprintf(
                'AutoCaption falhou ao entregar o output (%d): %s',
                $response->status(),
                Str::limit((string) file_get_contents($sinkPath), 300),
            ));
        }
    }

    private function client(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl())
            ->connectTimeout(10)
            ->timeout((int) config('services.autocaption.timeout', 300));
    }

    private function baseUrl(): string
    {
        return mb_rtrim((string) config('services.autocaption.base_url'), '/');
    }
}
