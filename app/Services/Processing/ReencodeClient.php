<?php

declare(strict_types=1);

namespace App\Services\Processing;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Client do microserviço reencode (porta 8790) no contrato novo: o Laravel
 * envia o binário por multipart e recebe o vídeo reencodado na resposta —
 * o serviço não toca no S3 (regra da casa). Síncrono; rodar só em job de fila.
 *
 * POST /reencode multipart {video, video_id?}
 *   → 200 binário (header X-Reencode: completed)
 *   → 200 JSON {status: skipped} quando o bitrate já está ok / desabilitado
 */
final readonly class ReencodeClient
{
    /**
     * Envia o arquivo local e grava a resposta em $sinkPath.
     * Retorna true quando reencodou (sink contém o vídeo), false quando skipou.
     */
    public function reencode(string $sourcePath, string $videoId, string $sinkPath): bool
    {
        $stream = fopen($sourcePath, 'rb');
        throw_unless(is_resource($stream), RuntimeException::class, sprintf('Arquivo local não encontrado: %s', $sourcePath));

        try {
            $response = $this->client()
                ->withOptions(['sink' => $sinkPath])
                ->attach('video', $stream, basename($sourcePath))
                ->post('/reencode', ['video_id' => $videoId]);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        if (! $response->successful()) {
            throw new RuntimeException(sprintf(
                'Reencode respondeu %d: %s',
                $response->status(),
                Str::limit((string) file_get_contents($sinkPath), 300),
            ));
        }

        if (str_contains((string) $response->header('Content-Type'), 'application/json')) {
            return false; // skipped — bitrate já ok ou reencode desligado
        }

        return true;
    }

    private function client(): PendingRequest
    {
        $request = Http::baseUrl($this->baseUrl())
            ->connectTimeout(10)
            ->timeout((int) config('services.reencode.timeout', 900));

        $token = (string) config('services.reencode.api_token', '');

        return $token === '' ? $request : $request->withHeaders(['X-Api-Token' => $token]);
    }

    private function baseUrl(): string
    {
        return mb_rtrim((string) config('services.reencode.base_url'), '/');
    }
}
