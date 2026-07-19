<?php

declare(strict_types=1);

namespace App\Services\Reencode;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

final readonly class ReencodeService
{
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
