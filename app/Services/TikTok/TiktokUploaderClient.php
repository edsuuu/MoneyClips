<?php

declare(strict_types=1);

namespace App\Services\TikTok;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Client da integração NOVA com o microserviço tiktok-uploader (porta 8090):
 * o Laravel baixa o vídeo do MinIO e envia o binário por multipart — o
 * microserviço não toca no S3 (regra da casa). A resposta é síncrona
 * (o Playwright publica dentro do request; verificação pode levar ~15 min,
 * por isso o timeout alto — este client só deve rodar dentro de job de fila).
 *
 * Contrato (MicroServices/TikTokUploader):
 *   POST /posts multipart {video, cookies (JSON string), title, hashtags}
 *     → 200 {status: completed|dry-run|restricted, title, detail?}
 *     → 401 {detail} = cookies inválidos (LoginFailedError)
 *   GET /health → {status, dry_run}
 */
final readonly class TiktokUploaderClient
{
    /**
     * @param  array<array-key, mixed>  $cookies  Cookies Playwright da conta.
     * @param  list<string>  $hashtags
     *
     * @throws SessionInvalidException quando o uploader responde 401.
     * @throws ConnectionException em timeout/conexão.
     * @throws RuntimeException em qualquer outra falha (4xx/5xx).
     */
    public function postVideo(string $videoPath, array $cookies, string $title, array $hashtags): TiktokUploadResult
    {
        $stream = Storage::disk('s3')->readStream($videoPath);
        throw_unless(is_resource($stream), RuntimeException::class, sprintf('Vídeo não encontrado no MinIO: %s', $videoPath));

        try {
            $response = $this->client()
                ->attach('video', $stream, basename($videoPath))
                ->post('/posts', [
                    'cookies' => json_encode($cookies, JSON_THROW_ON_ERROR),
                    'title' => $title,
                    'hashtags' => json_encode($hashtags, JSON_THROW_ON_ERROR),
                ]);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        if ($response->status() === 401) {
            throw new SessionInvalidException((string) ($response->json('detail') ?? 'Cookies inválidos — sessão TikTok expirada.'));
        }

        if (! $response->successful()) {
            throw new RuntimeException(sprintf(
                'Uploader TikTok respondeu %d: %s',
                $response->status(),
                Str::limit($response->body(), 300),
            ));
        }

        $detail = $response->json('detail');

        return new TiktokUploadResult(
            status: (string) ($response->json('status') ?? 'completed'),
            detail: is_string($detail) ? $detail : null,
        );
    }

    private function client(): PendingRequest
    {
        $request = Http::baseUrl($this->baseUrl())
            ->connectTimeout(10)
            ->timeout((int) config('services.tiktok_post.timeout', 1500))
            ->acceptJson();

        $token = (string) config('services.tiktok_post.api_token', '');

        return $token === '' ? $request : $request->withToken($token);
    }

    private function baseUrl(): string
    {
        return mb_rtrim((string) config('services.tiktok_post.base_url'), '/');
    }
}
