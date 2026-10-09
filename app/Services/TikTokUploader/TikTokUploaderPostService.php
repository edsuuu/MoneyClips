<?php

declare(strict_types=1);

namespace App\Services\TikTokUploader;

use App\Models\SocialPost;
use App\Services\Posting\PostProviderInterface;
use App\Services\Posting\PostResultData;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Client do microserviço TikTokUploader (Playwright com os cookies da conta,
 * não oficial). Assíncrono: o `POST /posts` só enfileira e devolve 202
 * {job_id}; o desfecho chega no webhook `/api/webhook/tiktok-post`, que acha
 * o post pelo `external_id`.
 */
final class TikTokUploaderPostService implements PostProviderInterface
{
    public function post(SocialPost $post, string $localPath): PostResultData
    {
        $account = $post->socialAccount;
        $cookies = $account->cookies;
        if (! is_array($cookies) || $cookies === []) {
            return PostResultData::failed('Conta TikTok sem cookies de sessão: cole a sessão em /contas.');
        }

        $video = fopen($localPath, 'rb');
        throw_unless(is_resource($video), RuntimeException::class, sprintf('Falha ao abrir "%s".', $localPath));

        $short = $post->youtubeShort;
        $request = Http::asMultipart()->timeout(config()->integer('services.tiktok_uploader.timeout'));
        $token = config()->string('services.tiktok_uploader.api_token');
        if ($token !== '') {
            $request = $request->withToken($token);
        }

        try {
            $response = $request->attach('video', $video, 'short.mp4', ['Content-Type' => 'video/mp4'])
                ->post(mb_rtrim(config()->string('services.tiktok_uploader.base_url'), '/').'/posts', [
                    'title' => mb_trim((string) $short->title),
                    'hashtags' => json_encode($short->captionHashtags(), JSON_THROW_ON_ERROR),
                    'cookies' => json_encode($cookies, JSON_THROW_ON_ERROR),
                    'webhook_url' => config()->string('services.tiktok_uploader.webhook_url'),
                    'account_id' => $account->uuid,
                ]);
        } catch (ConnectionException $connectionException) {
            Log::channel('daily')->error('[ERRO][Posting] TikTokUploader inacessível.', ['post_id' => $post->id, 'message' => $connectionException->getMessage()]);

            return PostResultData::failed('TikTokUploader inacessível (serviço fora do ar ou timeout). Se foi timeout o post pode ter entrado na fila: confira no TikTok antes de tentar de novo.');
        } finally {
            if (is_resource($video)) {
                fclose($video);
            }
        }

        $jobId = $response->json('job_id');
        if ($response->status() !== 202 || ! is_string($jobId) || $jobId === '') {
            $detail = $response->json('detail') ?? $response->json('errors');

            return PostResultData::failed(sprintf(
                'TikTokUploader recusou o post (HTTP %d): %s',
                $response->status(),
                is_string($detail) ? $detail : json_encode($detail, JSON_UNESCAPED_UNICODE),
            ));
        }

        return PostResultData::pending($jobId);
    }
}
