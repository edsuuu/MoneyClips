<?php

declare(strict_types=1);

namespace App\Services\API\Youtube;

use App\Exceptions\YoutubeApiException;
use App\Helpers\Hashtags;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Services\Posting\PostProviderInterface;
use App\Services\Posting\PostResultData;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Upload pela YouTube Data API v3 (`videos.insert`, protocolo resumable):
 * abre a sessão com os metadados e manda o arquivo em pedaços, seguindo o
 * `Range` que o Google devolve no 308. O vídeo só existe no canal quando o
 * último pedaço é aceito.
 */
final class YoutubePostService implements PostProviderInterface
{
    private const string TOKEN_URL = 'https://oauth2.googleapis.com/token';

    private const string UPLOAD_URL = 'https://www.googleapis.com/upload/youtube/v3/videos?uploadType=resumable&part=snippet,status';

    // ponytail: múltiplo de 256 KiB (exigência do resumable). Shorts têm dezenas de MB: 8 MiB por PUT mantém a memória baixa.
    private const int CHUNK_BYTES = 8 * 1024 * 1024;

    private const int TITLE_MAX_CHARS = 100;

    private const int DESCRIPTION_MAX_BYTES = 5000;

    private const int TAGS_MAX_CHARS = 500;

    private const string CATEGORY_PEOPLE_AND_BLOGS = '22';

    private const array PRIVACY_STATUSES = ['public', 'private', 'unlisted'];

    private const array QUOTA_REASONS = ['quotaExceeded', 'dailyLimitExceeded', 'rateLimitExceeded', 'userRateLimitExceeded'];

    public function post(SocialPost $post, string $localPath): PostResultData
    {
        $account = $post->socialAccount;
        $size = (int) filesize($localPath);

        try {
            $token = $this->accessToken($account);
            $privacy = $this->privacy($post);
            $sessionUrl = $this->startUpload($account, $token, $this->metadata($post, $privacy), $size);
            $video = $this->upload($account, $sessionUrl, $localPath, $size);
        } catch (YoutubeApiException $youtubeApiException) {
            return PostResultData::failed($youtubeApiException->getMessage());
        }

        $videoId = $video['id'] ?? null;
        if (! is_string($videoId) || $videoId === '') {
            return PostResultData::failed('YouTube aceitou o upload mas não devolveu o id do vídeo: confira no YouTube Studio antes de tentar de novo.');
        }

        $status = $video['status'] ?? null;
        $published = match (is_array($status) ? $status['privacyStatus'] ?? null : null) {
            'public' => 'public',
            'unlisted' => 'unlisted',
            'private' => 'private',
            default => $privacy,
        };

        if ($published !== $privacy) {
            Log::channel('daily')->warning('[WARN][Posting] YouTube publicou com outra privacidade (projeto Google não verificado trava em private).', [
                'post_id' => $post->id, 'video_id' => $videoId, 'requested' => $privacy, 'published' => $published,
            ]);
        }

        return PostResultData::published('https://www.youtube.com/shorts/'.$videoId, $published);
    }

    /**
     * @throws YoutubeApiException
     */
    private function accessToken(SocialAccount $account): string
    {
        $token = (string) $account->access_token;
        $expiresAt = $account->token_expires_at;
        if ($token !== '' && ($expiresAt === null || $expiresAt->subMinute()->isFuture())) {
            return $token;
        }

        $refreshToken = (string) $account->refresh_token;
        if ($refreshToken === '') {
            $this->invalidate($account);

            throw new YoutubeApiException('Token do YouTube expirou e a conta não tem refresh token: revincule o canal em /contas.');
        }

        try {
            $response = Http::asForm()->timeout(30)->post(self::TOKEN_URL, [
                'client_id' => config('services.google.client_id'),
                'client_secret' => config('services.google.client_secret'),
                'refresh_token' => $refreshToken,
                'grant_type' => 'refresh_token',
            ]);
        } catch (ConnectionException) {
            throw new YoutubeApiException('Google inacessível ao renovar o token do YouTube. Nada foi publicado.');
        }

        $accessToken = $response->json('access_token');
        if ($response->failed() || ! is_string($accessToken) || $accessToken === '') {
            $error = $response->json('error');
            if ($error === 'invalid_grant') {
                $this->invalidate($account);

                throw new YoutubeApiException('Acesso ao YouTube revogado ou expirado (invalid_grant): revincule o canal em /contas.');
            }

            throw new YoutubeApiException(sprintf('Falha ao renovar o token do YouTube (%s). Nada foi publicado.', is_string($error) ? $error : 'HTTP '.$response->status()));
        }

        $newRefreshToken = $response->json('refresh_token');
        $account->forceFill([
            'access_token' => $accessToken,
            'refresh_token' => is_string($newRefreshToken) && $newRefreshToken !== '' ? $newRefreshToken : $refreshToken,
            'token_expires_at' => now()->addSeconds((int) $response->json('expires_in', 3600)),
        ])->save();

        return $accessToken;
    }

    /**
     * Projeto Google sem verificação (auditoria da API): o YouTube trava todo
     * upload em private, então pedir public só gera divergência. Sem
     * `GOOGLE_YOUTUBE_APP_VERIFIED=true` o post sai private e fica registrado.
     *
     * @return 'public'|'private'|'unlisted'
     */
    private function privacy(SocialPost $post): string
    {
        $meta = $post->socialAccount->meta;
        $wanted = $post->privacy ?? (is_array($meta) && is_string($meta['privacy_status'] ?? null) ? $meta['privacy_status'] : 'public');
        if (! in_array($wanted, self::PRIVACY_STATUSES, true)) {
            $wanted = 'private';
        }

        if ($wanted !== 'private' && ! config()->boolean('services.google.youtube_app_verified')) {
            Log::channel('daily')->warning('[WARN][Posting] Projeto Google não verificado: o YouTube só aceita upload private — enviando como private.', [
                'post_id' => $post->id, 'requested' => $wanted,
            ]);

            return 'private';
        }

        return $wanted;
    }

    /**
     * @return array{snippet: array{title: string, description: string, tags: list<string>, categoryId: string}, status: array{privacyStatus: string, selfDeclaredMadeForKids: bool}}
     */
    private function metadata(SocialPost $post, string $privacy): array
    {
        $short = $post->youtubeShort;
        $title = mb_substr(mb_trim(str_replace(['<', '>'], '', (string) $short->title)), 0, self::TITLE_MAX_CHARS);

        $tags = [];
        $length = 0;
        foreach (Hashtags::parse(implode(' ', $short->hashtags ?? [])) as $hashtag) {
            $tag = str_replace(['<', '>'], '', mb_ltrim($hashtag, '#'));
            $length += mb_strlen($tag) + 1;
            if ($tag === '') {
                continue;
            }
            if ($length > self::TAGS_MAX_CHARS) {
                continue;
            }

            $tags[] = $tag;
        }

        return [
            'snippet' => [
                'title' => $title !== '' ? $title : 'Short',
                'description' => mb_strcut(str_replace(['<', '>'], '', $short->caption()), 0, self::DESCRIPTION_MAX_BYTES),
                'tags' => $tags,
                'categoryId' => self::CATEGORY_PEOPLE_AND_BLOGS,
            ],
            'status' => [
                'privacyStatus' => $privacy,
                'selfDeclaredMadeForKids' => false,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $metadata
     *
     * @throws YoutubeApiException
     */
    private function startUpload(SocialAccount $account, string $token, array $metadata, int $size): string
    {
        try {
            $response = Http::withToken($token)
                ->withHeaders(['X-Upload-Content-Type' => 'video/mp4', 'X-Upload-Content-Length' => (string) $size])
                ->timeout(60)
                ->post(self::UPLOAD_URL, $metadata);
        } catch (ConnectionException) {
            throw new YoutubeApiException('YouTube inacessível ao abrir o upload. Nada foi publicado.');
        }

        if ($response->failed()) {
            throw $this->apiError($account, $response, 'a abertura do upload');
        }

        $sessionUrl = $response->header('Location');
        throw_if($sessionUrl === '', YoutubeApiException::class, 'YouTube não devolveu a URL da sessão de upload. Nada foi publicado.');

        return $sessionUrl;
    }

    /**
     * @return array<array-key, mixed>
     *
     * @throws YoutubeApiException
     */
    private function upload(SocialAccount $account, string $sessionUrl, string $localPath, int $size): array
    {
        $handle = fopen($localPath, 'rb');
        throw_unless(is_resource($handle), RuntimeException::class, sprintf('Falha ao abrir "%s".', $localPath));

        try {
            $offset = 0;
            while (true) {
                fseek($handle, $offset);
                $chunk = (string) fread($handle, self::CHUNK_BYTES);
                $response = Http::withHeaders(['Content-Range' => sprintf('bytes %d-%d/%d', $offset, $offset + mb_strlen($chunk) - 1, $size)])
                    ->withBody($chunk, 'video/mp4')
                    ->timeout(300)
                    ->put($sessionUrl);

                if ($response->status() !== 308) {
                    break;
                }

                $next = $this->nextOffset($response);
                throw_if($next <= $offset, YoutubeApiException::class, 'Upload resumable do YouTube parou de avançar. O vídeo não foi criado; tente de novo.');

                $offset = $next;
            }
        } finally {
            fclose($handle);
        }

        if ($response->failed()) {
            throw $this->apiError($account, $response, 'o envio do vídeo');
        }

        return (array) $response->json();
    }

    private function nextOffset(Response $response): int
    {
        return preg_match('/bytes=0-(\d+)/', $response->header('Range'), $match) === 1 ? (int) $match[1] + 1 : 0;
    }

    private function apiError(SocialAccount $account, Response $response, string $step): YoutubeApiException
    {
        $reason = $response->json('error.errors.0.reason');
        $reason = is_string($reason) ? $reason : '';

        $message = $response->json('error.message');
        $message = is_string($message) ? mb_substr(strip_tags($message), 0, 300) : 'sem detalhe';

        Log::channel('daily')->warning('[WARN][Posting] YouTube Data API recusou.', [
            'account_id' => $account->id, 'step' => $step, 'status' => $response->status(), 'reason' => $reason,
        ]);

        if (in_array($reason, self::QUOTA_REASONS, true)) {
            return new YoutubeApiException(sprintf('Cota da YouTube Data API esgotada (%s): a cota diária volta à meia-noite do horário do Pacífico. Nada foi publicado; reagende.', $reason));
        }

        if ($reason === 'uploadLimitExceeded') {
            return new YoutubeApiException('O canal atingiu o limite de uploads do YouTube (uploadLimitExceeded). Nada foi publicado; reagende pra amanhã.');
        }

        if ($response->status() === 401) {
            $this->invalidate($account);

            return new YoutubeApiException('YouTube recusou o token (401): revincule o canal em /contas.');
        }

        return new YoutubeApiException(sprintf('YouTube recusou %s (HTTP %d%s): %s', $step, $response->status(), $reason !== '' ? ', '.$reason : '', $message));
    }

    private function invalidate(SocialAccount $account): void
    {
        $account->forceFill(['session_status' => SocialAccount::SESSION_INVALID])->save();
    }
}
