<?php

declare(strict_types=1);

namespace App\Services\TiktokPost;

use App\Models\TiktokPost;
use App\Support\Cast;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

final class TiktokPostService
{
    /**
     * @param  array<int, string>  $hashtags
     */
    public function queuePost(string $youtubeId, string $title, array $hashtags): string
    {
        $title = mb_trim($title) !== '' ? $title : $youtubeId;

        /** @var array<string, mixed> $response */
        $response = $this->client()
            ->post('/posts', [
                'video_id' => $youtubeId,
                'webhook_url' => $this->callbackUrl(),
                'title' => $title,
                'hashtags' => $this->normalizeHashtags($hashtags),
            ])
            ->throw()
            ->json();

        $jobId = Cast::str($response['job_id'] ?? '');

        throw_if($jobId === '', RuntimeException::class, 'Microserviço tiktok-post não retornou job_id.');

        TiktokPost::query()->updateOrCreate(
            ['uuid' => $jobId],
            [
                'youtube_id' => $youtubeId,
                'video_key' => sprintf('shorts/%s.mp4', $youtubeId),
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
        return Cast::str(config('microservices.tiktok_post.callback_url'))
            ?: url('/api/tiktok-posts/callback');
    }

    private function client(): PendingRequest
    {
        $baseUrl = Cast::str(config('microservices.tiktok_post.base_url')) ?: 'http://127.0.0.1:8090';
        $timeout = Cast::int(config('microservices.tiktok_post.timeout')) ?: 30;
        $token = Cast::str(config('microservices.tiktok_post.api_token'));

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
