<?php

declare(strict_types=1);

namespace App\Services\Video;

use App\Models\VideoCutEdit;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Client do endpoint assíncrono /face-tracking do serviço media (:8770): o
 * Laravel envia o clip por multipart, recebe 202 {job_id} na hora e os
 * keyframes (mais a timeline de locutor) chegam por webhook
 * (/api/webhook/face-tracking). Mesmo desenho do TranscribeService — o
 * serviço não tem credencial de storage, então quem grava o resultado no
 * MinIO é o Laravel.
 */
final readonly class FaceTrackingService
{
    public function createTracking(string $sourcePath, VideoCutEdit $edit): string
    {
        $stream = fopen($sourcePath, 'rb');
        throw_unless(is_resource($stream), RuntimeException::class, sprintf('Clip local não encontrado: %s', $sourcePath));

        $payload = [
            'uuid' => $edit->uuid,
            'webhook_url' => (string) config('services.face_tracking.webhook_url'),
            'max_keyframes' => (string) config('services.face_tracking.max_keyframes', 40),
        ];

        Log::channel('daily')->info('[INFO][FaceTracking] POST /face-tracking', [
            'base_url' => $this->baseUrl(),
            'payload' => $payload,
            'source_bytes' => filesize($sourcePath),
        ]);

        try {
            $response = $this->client()
                ->attach('video', $stream, 'clip.mp4')
                ->post('/face-tracking', $payload);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        Log::channel('daily')->info('[INFO][FaceTracking] Resposta do media.', [
            'uuid' => $edit->uuid,
            'status' => $response->status(),
            'body' => Str::limit($response->body(), 300),
        ]);

        if (! $response->successful()) {
            throw new RuntimeException(sprintf(
                'Serviço media respondeu %d: %s',
                $response->status(),
                Str::limit($response->body(), 300),
            ));
        }

        $jobId = $response->json('job_id');
        throw_unless(is_string($jobId) && $jobId !== '', RuntimeException::class, 'Serviço media não retornou o job_id.');

        return $jobId;
    }

    private function baseUrl(): string
    {
        return mb_rtrim((string) config('services.face_tracking.base_url'), '/');
    }

    private function client(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl())
            ->connectTimeout(10)
            ->timeout((int) config('services.face_tracking.timeout', 120));
    }
}
