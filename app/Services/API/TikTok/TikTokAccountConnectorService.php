<?php

declare(strict_types=1);

namespace App\Services\API\TikTok;

use App\Enums\PostProviderEnum;
use App\Exceptions\TikTokApiException;
use App\Models\SocialAccount;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * TikTok Login Kit (OAuth v2): autoriza em /contas, troca o code pelos tokens
 * e renova o access token (24h) com o refresh token (365d). Credenciais em
 * `social_accounts` (platform=tiktok, provider=tiktok_official).
 */
final class TikTokAccountConnectorService
{
    private const string AUTHORIZE_URL = 'https://www.tiktok.com/v2/auth/authorize/';

    private const string TOKEN_URL = 'https://open.tiktokapis.com/v2/oauth/token/';

    private const string USER_INFO_URL = 'https://open.tiktokapis.com/v2/user/info/';

    private const string SCOPES = 'user.info.basic,video.publish';

    public function configured(): bool
    {
        return filled(config('services.tiktok_official.client_key')) && filled(config('services.tiktok_official.client_secret'));
    }

    public function authorizeUrl(string $state): string
    {
        return self::AUTHORIZE_URL.'?'.http_build_query([
            'client_key' => config('services.tiktok_official.client_key'),
            'scope' => self::SCOPES,
            'response_type' => 'code',
            'redirect_uri' => config('services.tiktok_official.redirect'),
            'state' => $state,
        ]);
    }

    /**
     * @throws TikTokApiException
     */
    public function fromCallback(string $code, int $userId): SocialAccount
    {
        $tokens = $this->requestToken([
            'code' => $code,
            'grant_type' => 'authorization_code',
            'redirect_uri' => config('services.tiktok_official.redirect'),
        ], 'a troca do código de autorização');

        $openId = $tokens->json('open_id');
        throw_if(! is_string($openId) || $openId === '', TikTokApiException::class, 'TikTok não devolveu o open_id da conta.');

        $name = $this->displayName((string) $tokens->json('access_token'));

        /** @var SocialAccount $account */
        $account = SocialAccount::query()->updateOrCreate(
            ['platform' => 'tiktok', 'external_account_id' => $openId],
            [
                ...$this->tokenAttributes($tokens),
                'user_id' => $userId,
                'provider' => PostProviderEnum::TiktokOfficial,
                'name' => $name,
                'scopes' => array_values(array_filter(explode(',', (string) $tokens->json('scope')))),
                'is_active' => true,
                'session_status' => SocialAccount::SESSION_VALID,
            ],
        );

        return $account;
    }

    /**
     * @throws TikTokApiException
     */
    public function accessToken(SocialAccount $account): string
    {
        $token = (string) $account->access_token;
        $expiresAt = $account->token_expires_at;
        if ($token !== '' && (is_null($expiresAt) || $expiresAt->subMinutes(5)->isFuture())) {
            return $token;
        }

        $refreshToken = (string) $account->refresh_token;
        if ($refreshToken === '') {
            $this->invalidate($account);

            throw new TikTokApiException('Token do TikTok expirou e a conta não tem refresh token: revincule em /contas.');
        }

        $tokens = $this->requestToken(['grant_type' => 'refresh_token', 'refresh_token' => $refreshToken], 'a renovação do token', $account);

        $account->forceFill($this->tokenAttributes($tokens))->save();

        return (string) $account->access_token;
    }

    public function invalidate(SocialAccount $account): void
    {
        $account->forceFill(['session_status' => SocialAccount::SESSION_INVALID])->save();
    }

    /**
     * @param  array<string, mixed>  $params
     *
     * @throws TikTokApiException
     */
    private function requestToken(array $params, string $step, ?SocialAccount $account = null): Response
    {
        try {
            $response = Http::asForm()->timeout(30)->post(self::TOKEN_URL, [
                'client_key' => config('services.tiktok_official.client_key'),
                'client_secret' => config('services.tiktok_official.client_secret'),
                ...$params,
            ]);
        } catch (ConnectionException) {
            throw new TikTokApiException(sprintf('TikTok inacessível durante %s. Nada foi publicado.', $step));
        }

        $accessToken = $response->json('access_token');
        if ($response->successful() && is_string($accessToken) && $accessToken !== '') {
            return $response;
        }

        $error = $response->json('error');
        $error = is_string($error) && $error !== '' ? $error : 'HTTP '.$response->status();
        Log::channel('daily')->warning('[WARN][TikTok] OAuth recusado.', ['step' => $step, 'error' => $error, 'log_id' => $response->json('log_id')]);

        if ($error !== 'invalid_grant') {
            throw new TikTokApiException(sprintf('TikTok recusou %s (%s).', $step, $error));
        }

        if ($account instanceof SocialAccount) {
            $this->invalidate($account);
        }

        throw new TikTokApiException(sprintf('TikTok recusou %s (%s). Revincule a conta em /contas.', $step, $error));
    }

    private function displayName(string $accessToken): string
    {
        try {
            $response = Http::withToken($accessToken)->timeout(30)->get(self::USER_INFO_URL, ['fields' => 'open_id,display_name']);
            $name = $response->json('data.user.display_name');
            if ($response->successful() && is_string($name) && $name !== '') {
                return $name;
            }
        } catch (ConnectionException) {
            Log::channel('daily')->warning('[WARN][TikTok] user/info inacessível — usando nome padrão.');
        }

        return 'Conta TikTok';
    }

    /**
     * @return array{access_token: string, refresh_token: string, token_expires_at: CarbonImmutable}
     */
    private function tokenAttributes(Response $tokens): array
    {
        return [
            'access_token' => (string) $tokens->json('access_token'),
            'refresh_token' => (string) $tokens->json('refresh_token'),
            'token_expires_at' => now()->addSeconds((int) $tokens->json('expires_in', 86400)),
        ];
    }
}
