<?php

declare(strict_types=1);

namespace App\Services\TikTok;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Cliente HTTP do microserviço tiktok-uploader (porta 8780).
 *
 * As chamadas só enfileiram: o uploader processa um post por vez (navegador
 * único) e grava cada transição na tabela tiktok_posts deste banco — o
 * acompanhamento é feito pelo model TiktokPost, sem polling HTTP.
 */
final class TikTokUploaderClient
{
    /**
     * Enfileira a postagem de um vídeo específico do storage. Retorna o uuid
     * do job (que também é a chave da linha em tiktok_posts).
     *
     * @param  array<int, string>  $hashtags
     */
    public function createPost(string $videoKey, string $title, array $hashtags, ?string $youtubeId = null): string
    {
        $payload = [
            'video_key' => $videoKey,
            'title' => $title,
            'hashtags' => $hashtags,
        ];
        if ($youtubeId !== null) {
            $payload['youtube_id'] = $youtubeId;
        }

        /** @var array<string, mixed> $res */
        $res = $this->client()
            ->post('/posts', $payload)
            ->throw()
            ->json();

        return $this->jobIdFrom($res);
    }

    /**
     * Modo legado: o uploader sorteia uma pasta pendente do bucket (estoque
     * antigo em shorts/{id}/) e posta. Retorna o uuid do job.
     */
    public function postNext(): string
    {
        /** @var array<string, mixed> $res */
        $res = $this->client()
            ->post('/posts/next')
            ->throw()
            ->json();

        return $this->jobIdFrom($res);
    }

    /** Verifica se o microserviço está no ar. */
    public function isHealthy(): bool
    {
        try {
            return $this->client()
                ->timeout(5)
                ->get('/health')
                ->successful();
        } catch (Throwable) {
            return false;
        }
    }

    /** @param array<string, mixed> $res */
    private function jobIdFrom(array $res): string
    {
        $jobId = (string) ($res['job_id'] ?? '');

        throw_if($jobId === '', RuntimeException::class, 'Microserviço tiktok-uploader não retornou job_id.');

        return $jobId;
    }

    private function client(): PendingRequest
    {
        $baseUrl = (string) (config('tiktok-uploader.base_url')) ?: 'http://127.0.0.1:8780';
        $timeout = (int) (config('tiktok-uploader.timeout')) ?: 30;

        return Http::baseUrl($baseUrl)
            ->timeout($timeout)
            ->acceptJson()
            ->asJson();
    }
}
