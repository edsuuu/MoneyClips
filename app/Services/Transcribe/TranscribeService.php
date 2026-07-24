<?php

declare(strict_types=1);

namespace App\Services\Transcribe;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Client do endpoint assíncrono /transcriptions do transcriber (:8780): o
 * Laravel envia o wav por multipart, recebe 202 {job_id} na hora e o desfecho
 * (a transcrição) chega por webhook (/api/webhook/transcribe). O serviço só
 * transcreve — quem grava o resultado no MinIO é o Laravel.
 */
final readonly class TranscribeService
{
    public function createTranscription(string $sourcePath, string $videoUuid): string
    {
        $stream = fopen($sourcePath, 'rb');
        throw_unless(is_resource($stream), RuntimeException::class, sprintf('Arquivo local não encontrado: %s', $sourcePath));

        try {
            $response = $this->client()
                ->attach('audio', $stream, 'audio.wav')
                ->post('/transcriptions', [
                    'uuid' => $videoUuid,
                    'webhook_url' => (string) config('services.transcribe.webhook_url'),
                ]);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        if (! $response->successful()) {
            throw new RuntimeException(sprintf(
                'Transcriber respondeu %d: %s',
                $response->status(),
                Str::limit($response->body(), 300),
            ));
        }

        $jobId = $response->json('job_id');
        throw_unless(is_string($jobId) && $jobId !== '', RuntimeException::class, 'Transcriber não retornou o job_id.');

        return $jobId;
    }

    private function client(): PendingRequest
    {
        return Http::baseUrl(mb_rtrim((string) config('services.transcribe.base_url'), '/'))
            ->connectTimeout(10)
            ->timeout((int) config('services.transcribe.timeout', 60));
    }
}
