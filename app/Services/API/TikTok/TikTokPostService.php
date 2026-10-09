<?php

declare(strict_types=1);

namespace App\Services\API\TikTok;

use App\Exceptions\TikTokApiException;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Services\Posting\PostProviderInterface;
use App\Services\Posting\PostResultData;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use RuntimeException;

/**
 * Content Posting API (direct post, FILE_UPLOAD): creator_info/query →
 * video/init → PUT dos pedaços na upload_url → status/fetch até
 * PUBLISH_COMPLETE ou FAILED.
 */
final readonly class TikTokPostService implements PostProviderInterface
{
    private const string API_URL = 'https://open.tiktokapis.com/v2/post/publish/';

    private const int SINGLE_CHUNK_MAX_BYTES = 64 * 1024 * 1024;

    // ponytail: até 64 MiB vai num PUT só (regra do TikTok); acima, pedaços de 10 MiB e o último absorve o resto (≤ 20 MiB, teto é 128).
    private const int CHUNK_BYTES = 10 * 1024 * 1024;

    private const int CAPTION_MAX_CHARS = 2200;

    private const int POLL_SECONDS = 5;

    private const int POLL_ATTEMPTS = 120;

    private const array REVOKED_CODES = ['access_token_invalid', 'scope_not_authorized', 'scope_permission_missed'];

    private const array QUOTA_CODES = [
        'rate_limit_exceeded' => 'limite de requisições da API do TikTok excedido',
        'spam_risk_too_many_posts' => 'a conta atingiu o limite diário de posts pela API',
        'spam_risk_too_many_pending_share' => 'a conta tem posts demais pendentes de processamento',
        'reached_active_user_cap' => 'o app atingiu a cota diária de usuários ativos',
    ];

    public function __construct(private TikTokAccountConnectorService $connector) {}

    public function post(SocialPost $post, string $localPath): PostResultData
    {
        $account = $post->socialAccount;
        $size = (int) filesize($localPath);
        if ($size < 1) {
            return PostResultData::failed('Vídeo vazio: nada foi enviado ao TikTok.');
        }

        try {
            $token = $this->connector->accessToken($account);
            $creator = $this->call($account, $token, 'creator_info/query/', [], 'a consulta do criador');
            $privacyLevel = $this->privacyLevel($post, $creator);

            $chunkSize = $size <= self::SINGLE_CHUNK_MAX_BYTES ? $size : self::CHUNK_BYTES;
            $chunkCount = max(intdiv($size, $chunkSize), 1);

            $init = $this->call($account, $token, 'video/init/', [
                'post_info' => [
                    'title' => mb_substr($post->youtubeShort->caption(), 0, self::CAPTION_MAX_CHARS),
                    'privacy_level' => $privacyLevel,
                    'disable_comment' => (bool) ($creator['comment_disabled'] ?? false),
                    'disable_duet' => (bool) ($creator['duet_disabled'] ?? false),
                    'disable_stitch' => (bool) ($creator['stitch_disabled'] ?? false),
                ],
                'source_info' => [
                    'source' => 'FILE_UPLOAD',
                    'video_size' => $size,
                    'chunk_size' => $chunkSize,
                    'total_chunk_count' => $chunkCount,
                ],
            ], 'a abertura do upload');

            $publishId = $init['publish_id'] ?? null;
            $uploadUrl = $init['upload_url'] ?? null;
            throw_if(! is_string($publishId) || ! is_string($uploadUrl), TikTokApiException::class, 'TikTok não devolveu publish_id/upload_url. Nada foi publicado.');

            $this->upload($uploadUrl, $localPath, $size, $chunkSize, $chunkCount);

            return $this->waitForPublish($account, $token, $publishId, $creator, $privacyLevel);
        } catch (TikTokApiException $tikTokApiException) {
            return PostResultData::failed($tikTokApiException->getMessage());
        }
    }

    /**
     * App sem auditoria só pode postar SELF_ONLY (e só em conta privada): sem
     * `TIKTOK_OFFICIAL_AUDITED=true` o post sai SELF_ONLY e fica registrado.
     *
     * @param  array<array-key, mixed>  $creator
     *
     * @throws TikTokApiException
     */
    private function privacyLevel(SocialPost $post, array $creator): string
    {
        $wantsPublic = ($post->privacy ?? 'public') === 'public';
        $level = $wantsPublic && config()->boolean('services.tiktok_official.audited') ? 'PUBLIC_TO_EVERYONE' : 'SELF_ONLY';

        if ($wantsPublic && $level === 'SELF_ONLY') {
            Log::channel('daily')->warning('[WARN][Posting] App TikTok sem auditoria: postando SELF_ONLY.', ['post_id' => $post->id]);
        }

        $options = $creator['privacy_level_options'] ?? [];
        $options = is_array($options) ? $options : [];
        if (! in_array($level, $options, true)) {
            throw new TikTokApiException(sprintf('Privacidade %s não permitida pela conta do TikTok (opções: %s). Nada foi publicado.', $level, implode(', ', array_filter($options, is_string(...))) ?: 'nenhuma'));
        }

        return $level;
    }

    /**
     * @throws TikTokApiException
     */
    private function upload(string $uploadUrl, string $localPath, int $size, int $chunkSize, int $chunkCount): void
    {
        $handle = fopen($localPath, 'rb');
        throw_unless(is_resource($handle), RuntimeException::class, sprintf('Falha ao abrir "%s".', $localPath));

        try {
            for ($index = 0; $index < $chunkCount; $index++) {
                $first = $index * $chunkSize;
                $last = $index === $chunkCount - 1 ? $size - 1 : $first + $chunkSize - 1;
                fseek($handle, $first);
                $chunk = (string) fread($handle, max($last - $first + 1, 1));

                $response = Http::withHeaders(['Content-Range' => sprintf('bytes %d-%d/%d', $first, $last, $size)])
                    ->withBody($chunk, 'video/mp4')
                    ->timeout(300)
                    ->put($uploadUrl);

                if ($response->failed()) {
                    throw new TikTokApiException(sprintf('TikTok recusou o pedaço %d/%d do vídeo (HTTP %d). Nada foi publicado.', $index + 1, $chunkCount, $response->status()));
                }
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param  array<array-key, mixed>  $creator
     *
     * @throws TikTokApiException
     */
    private function waitForPublish(SocialAccount $account, string $token, string $publishId, array $creator, string $privacyLevel): PostResultData
    {
        $privacy = $privacyLevel === 'PUBLIC_TO_EVERYONE' ? 'public' : 'private';
        $step = sprintf('a consulta do status (o vídeo já foi enviado, publish_id %s: confira no app antes de tentar de novo)', $publishId);

        for ($attempt = 0; $attempt < self::POLL_ATTEMPTS; $attempt++) {
            if ($attempt > 0) {
                Sleep::for(self::POLL_SECONDS)->seconds();
            }

            $response = $this->send($token, 'status/fetch/', ['publish_id' => $publishId]);
            if (! $response instanceof Response || $response->serverError() || $response->status() === 429) {
                Log::channel('daily')->warning('[WARN][Posting] Falha transitória no status/fetch do TikTok — tentando de novo.', [
                    'account_id' => $account->id, 'publish_id' => $publishId, 'status' => $response?->status(),
                ]);

                continue;
            }

            $status = $this->data($account, $response, $step);

            if (($status['status'] ?? null) === 'PUBLISH_COMPLETE') {
                $ids = $status['publicaly_available_post_id'] ?? [];
                $postId = is_array($ids) ? ($ids[0] ?? null) : null;
                $username = $creator['creator_username'] ?? null;
                $url = is_scalar($postId) && is_string($username) && $username !== ''
                    ? sprintf('https://www.tiktok.com/@%s/video/%s', $username, $postId)
                    : null;

                return PostResultData::published($url, $privacy);
            }

            if (($status['status'] ?? null) === 'FAILED') {
                $reason = $status['fail_reason'] ?? null;

                return PostResultData::failed(sprintf('TikTok não publicou o vídeo (%s).', is_string($reason) && $reason !== '' ? $reason : 'sem motivo'));
            }
        }

        return PostResultData::failed(sprintf('TikTok não confirmou a publicação em %d min (publish_id %s): confira no app antes de tentar de novo.', intdiv(self::POLL_SECONDS * self::POLL_ATTEMPTS, 60), $publishId));
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<array-key, mixed>
     *
     * @throws TikTokApiException
     */
    private function call(SocialAccount $account, string $token, string $path, array $body, string $step): array
    {
        $response = $this->send($token, $path, $body);
        throw_unless($response instanceof Response, TikTokApiException::class, sprintf('TikTok inacessível durante %s.', $step));

        return $this->data($account, $response, $step);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function send(string $token, string $path, array $body): ?Response
    {
        try {
            $request = Http::withToken($token)->timeout(60);

            return $body === []
                ? $request->withBody('{}', 'application/json; charset=UTF-8')->post(self::API_URL.$path)
                : $request->asJson()->post(self::API_URL.$path, $body);
        } catch (ConnectionException) {
            return null;
        }
    }

    /**
     * @return array<array-key, mixed>
     *
     * @throws TikTokApiException
     */
    private function data(SocialAccount $account, Response $response, string $step): array
    {
        $code = $response->json('error.code');
        $code = is_string($code) ? $code : '';
        if ($response->successful() && $code === 'ok') {
            $data = $response->json('data');

            return is_array($data) ? $data : [];
        }

        $message = $response->json('error.message');
        $message = is_string($message) && $message !== '' ? mb_substr($message, 0, 300) : 'sem detalhe';
        Log::channel('daily')->warning('[WARN][Posting] TikTok Content Posting API recusou.', [
            'account_id' => $account->id, 'step' => $step, 'status' => $response->status(), 'code' => $code, 'log_id' => $response->json('error.log_id'),
        ]);

        if (in_array($code, self::REVOKED_CODES, true) || $response->status() === 401) {
            $this->connector->invalidate($account);

            throw new TikTokApiException(sprintf('TikTok recusou o acesso (%s): revincule a conta em /contas.', $code !== '' ? $code : 'HTTP 401'));
        }

        if (isset(self::QUOTA_CODES[$code])) {
            throw new TikTokApiException(sprintf('Cota do TikTok: %s (%s). Nada foi publicado; reagende.', self::QUOTA_CODES[$code], $code));
        }

        throw_if($code === 'unaudited_client_can_only_post_to_private_accounts', TikTokApiException::class, 'App TikTok sem auditoria só posta em conta privada: deixe a conta privada no app do TikTok ou conclua a auditoria.');

        throw new TikTokApiException(sprintf('TikTok recusou %s (HTTP %d%s): %s', $step, $response->status(), $code !== '' ? ', '.$code : '', $message));
    }
}
