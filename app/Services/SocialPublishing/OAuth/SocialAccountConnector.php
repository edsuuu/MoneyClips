<?php

declare(strict_types=1);

namespace App\Services\SocialPublishing\OAuth;

use App\Models\SocialAccount;
use App\Support\Cast;
use Illuminate\Support\Facades\Http;
use Laravel\Socialite\Two\User as SocialiteUser;
use Throwable;

/**
 * Persiste/atualiza contas sociais a partir do retorno do OAuth (Socialite).
 * Cada método trata uma plataforma e devolve a lista de contas conectadas.
 */
final class SocialAccountConnector
{
    /**
     * YouTube (Google): guarda token + refresh e busca o canal do usuário.
     *
     * @return list<SocialAccount>
     */
    public function fromGoogle(SocialiteUser $user, ?int $userId): array
    {
        $channelId = null;
        $channelTitle = $user->getName() ?: ($user->getNickname() ?: 'Canal do YouTube');

        // Busca o canal para guardar o id/título (não é obrigatório para publicar).
        try {
            $resp = Http::withToken(Cast::str($user->token))->get('https://www.googleapis.com/youtube/v3/channels', [
                'part' => 'id,snippet',
                'mine' => 'true',
            ]);
            $items = $resp->json('items');
            if ($resp->successful() && is_array($items)) {
                $item = Cast::arr($items[0] ?? []);
                $channelId = Cast::str($item['id'] ?? '');
                $snippet = Cast::arr($item['snippet'] ?? []);
                $channelTitle = Cast::str($snippet['title'] ?? '') ?: $channelTitle;
            }
        } catch (Throwable) {
            // segue sem o canal; o token já é suficiente para o upload
        }

        $account = $this->upsert($userId, 'youtube', $channelId ?: $user->getId(), $channelTitle, [
            'access_token' => Cast::str($user->token),
            'refresh_token' => Cast::str($user->refreshToken) ?: null,
            'token_expires_at' => $user->expiresIn !== null ? now()->addSeconds(Cast::int($user->expiresIn)) : null,
            'meta' => ['channel_id' => $channelId, 'privacy_status' => 'public'],
        ]);

        return [$account];
    }

    /**
     * TikTok: guarda token + refresh (open_id como id externo).
     *
     * @return list<SocialAccount>
     */
    public function fromTikTok(SocialiteUser $user, ?int $userId): array
    {
        $account = $this->upsert($userId, 'tiktok', $user->getId(), $user->getName() ?: 'TikTok', [
            'access_token' => Cast::str($user->token),
            'refresh_token' => Cast::str($user->refreshToken) ?: null,
            'token_expires_at' => $user->expiresIn !== null ? now()->addSeconds(Cast::int($user->expiresIn)) : null,
            'meta' => ['open_id' => $user->getId(), 'privacy_level' => 'SELF_ONLY'],
        ]);

        return [$account];
    }

    /**
     * Meta: troca por token de longa duração, enumera Páginas e contas IG Business
     * vinculadas, criando uma conta facebook por página e uma instagram por IG.
     *
     * @return list<SocialAccount>
     */
    public function fromMeta(SocialiteUser $user, ?int $userId): array
    {
        $version = Cast::str(config('social-publishing.graph_version', 'v21.0'));
        $base = 'https://graph.facebook.com/'.$version;

        $longLived = $this->exchangeLongLivedMetaToken($base, Cast::str($user->token));

        $resp = Http::get($base.'/me/accounts', [
            'fields' => 'id,name,access_token,instagram_business_account{id,username}',
            'access_token' => $longLived,
        ]);

        $accounts = [];

        foreach (Cast::arr($resp->json('data')) as $page) {
            if (! is_array($page)) {
                continue;
            }

            $pageId = Cast::str($page['id'] ?? '');
            $pageToken = Cast::str($page['access_token'] ?? '');
            $pageName = Cast::str($page['name'] ?? '') ?: 'Página';
            if ($pageId === '') {
                continue;
            }

            if ($pageToken === '') {
                continue;
            }

            $accounts[] = $this->upsert($userId, 'facebook', $pageId, $pageName, [
                'access_token' => $pageToken,
                'refresh_token' => null,
                'token_expires_at' => null, // page token derivado de long-lived não expira
                'meta' => ['page_id' => $pageId],
            ]);

            $ig = Cast::arr($page['instagram_business_account'] ?? []);
            $igId = Cast::str($ig['id'] ?? '');
            if ($igId !== '') {
                $igName = '@'.(Cast::str($ig['username'] ?? '') ?: $pageName);

                $accounts[] = $this->upsert($userId, 'instagram', $igId, $igName, [
                    'access_token' => $pageToken,
                    'refresh_token' => null,
                    'token_expires_at' => null,
                    'meta' => ['ig_user_id' => $igId, 'page_id' => $pageId],
                ]);
            }
        }

        return $accounts;
    }

    private function exchangeLongLivedMetaToken(string $base, string $shortToken): string
    {
        $resp = Http::get($base.'/oauth/access_token', [
            'grant_type' => 'fb_exchange_token',
            'client_id' => config('services.facebook.client_id'),
            'client_secret' => config('services.facebook.client_secret'),
            'fb_exchange_token' => $shortToken,
        ]);

        $long = Cast::str($resp->json('access_token'));

        return $long !== '' ? $long : $shortToken;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function upsert(?int $userId, string $platform, string $externalId, string $name, array $attributes): SocialAccount
    {
        /** @var SocialAccount $account */
        $account = SocialAccount::query()->updateOrCreate(
            ['platform' => $platform, 'external_account_id' => $externalId],
            array_merge($attributes, [
                'user_id' => $userId,
                'name' => $name,
                'is_active' => true,
            ]),
        );

        return $account;
    }
}
