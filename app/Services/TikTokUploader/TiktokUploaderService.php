<?php

declare(strict_types=1);

namespace App\Services\TikTokUploader;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

final readonly class TiktokUploaderService
{
    /**
     * Enfileira o post no uploader e retorna o job_id do microserviço
     * (gravado em social_posts.uuid pra casar com o webhook). O account_id é
     * ecoado no webhook — garante que cookies renovados/session_status caiam
     * na conta certa mesmo com 2+ contas cadastradas.
     *
     * @param  array<array-key, mixed>  $cookies  Cookies Playwright da conta.
     * @param  list<string>  $hashtags
     *
     * @throws ConnectionException em timeout/conexão.
     * @throws RuntimeException quando o uploader recusa o enfileiramento.
     */
    public function queuePost(string $videoPath, array $cookies, string $title, array $hashtags, int $accountId): string
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
                    'webhook_url' => (string) config('services.tiktok_post.webhook_url'),
                    'account_id' => (string) $accountId,
                ]);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        $jobId = $response->json('job_id');

        if (! $response->accepted() || ! is_string($jobId) || $jobId === '') {
            throw new RuntimeException(sprintf(
                'Uploader TikTok recusou o enfileiramento (%d): %s',
                $response->status(),
                Str::limit($response->body(), 300),
            ));
        }

        return $jobId;
    }

    private function client(): PendingRequest
    {
        $request = Http::baseUrl($this->baseUrl())
            ->connectTimeout(10)
            ->timeout((int) config('services.tiktok_post.timeout', 120))
            ->acceptJson();

        $token = (string) config('services.tiktok_post.api_token', '');

        return $token === '' ? $request : $request->withToken($token);
    }

    private function baseUrl(): string
    {
        return mb_rtrim((string) config('services.tiktok_post.base_url'), '/');
    }
}
