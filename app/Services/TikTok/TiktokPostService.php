<?php

declare(strict_types=1);

namespace App\Services\TikTok;

use App\Models\TiktokPost;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

final class TiktokPostService
{
    /**
     * @param  array<int, string>  $hashtags
     * @param  string|null  $videoKey  Chave exata do objeto no storage (layout
     *                                 aninhado do download-shorts). Quando null,
     *                                 o uploader cai no layout plano shorts/{id}.mp4.
     */
    public function queuePost(string $youtubeId, string $title, array $hashtags, ?string $videoKey = null): string
    {
        $title = mb_trim($title) !== '' ? $title : $youtubeId;

        $payload = [
            'video_id' => $youtubeId,
            'webhook_url' => $this->callbackUrl(),
            'title' => $title,
            'hashtags' => $this->normalizeHashtags($hashtags),
        ];
        if ($videoKey !== null && $videoKey !== '') {
            $payload['video_key'] = $videoKey;
        }

        /** @var array<string, mixed> $response */
        $response = $this->client()
            ->post('/posts', $payload)
            ->throw()
            ->json();

        $jobId = (string) ($response['job_id'] ?? '');

        throw_if($jobId === '', RuntimeException::class, 'Microserviço tiktok-post não retornou job_id.');

        TiktokPost::query()->updateOrCreate(
            ['uuid' => $jobId],
            [
                'youtube_id' => $youtubeId,
                'video_key' => $videoKey ?? sprintf('shorts/%s.mp4', $youtubeId),
                'title' => $title,
                'hashtags' => $this->normalizeHashtags($hashtags),
                'status' => 'queued',
                'error' => null,
                'requested_at' => now(),
            ],
        );

        return $jobId;
    }

    public function health(): bool
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

    /**
     * @return array<string, mixed>
     */
    public function session(): array
    {
        /** @var array<string, mixed> $response */
        $response = $this->client()
            ->get('/session')
            ->throw()
            ->json();

        return $response;
    }

    /**
     * Dispara o login explícito no uploader (POST /login). O serviço abre um
     * navegador e loga por email/senha — pode demorar, daí o timeout estendido.
     * Devolve a SessionView resultante (account, has_cookies, expired, valid).
     *
     * @return array<string, mixed>
     */
    public function login(bool $force = false, ?bool $headless = null): array
    {
        $payload = ['force' => $force];
        if ($headless !== null) {
            $payload['headless'] = $headless;
        }

        /** @var array<string, mixed> $response */
        $response = $this->client()
            ->timeout(180)
            ->post('/login', $payload)
            ->throw()
            ->json();

        return $response;
    }

    /**
     * Injeta uma sessão (cookies exportados de um login local) no uploader via
     * POST /session — o login pode ser gerido no Laravel e empurrado para cá.
     *
     * @param  array<int, array<string, mixed>>  $cookies
     * @return array<string, mixed>
     */
    public function injectSession(array $cookies): array
    {
        /** @var array<string, mixed> $response */
        $response = $this->client()
            ->post('/session', ['cookies' => $cookies])
            ->throw()
            ->json();

        return $response;
    }

    /**
     * @param  array<int, string>  $hashtags
     * @return array<int, string>
     */
    private function normalizeHashtags(array $hashtags): array
    {
        return array_values(array_filter(
            array_map(
                mb_trim(...),
                array_filter($hashtags, is_string(...)),
            ),
            static fn (string $tag): bool => $tag !== '',
        ));
    }

    private function callbackUrl(): string
    {
        return (string) (config('services.tiktok_post.callback_url'))
            ?: url('/api/tiktok-posts/callback');
    }

    private function client(): PendingRequest
    {
        $baseUrl = (string) (config('services.tiktok_post.base_url')) ?: 'http://127.0.0.1:8090';
        $timeout = (int) (config('services.tiktok_post.timeout')) ?: 30;
        $token = (string) (config('services.tiktok_post.api_token'));

        $request = Http::baseUrl($baseUrl)
            ->timeout($timeout)
            ->acceptJson()
            ->asJson();

        if ($token !== '') {
            return $request->withToken($token);
        }

        return $request;
    }
}
