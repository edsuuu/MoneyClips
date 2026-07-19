<?php

declare(strict_types=1);

namespace App\Services\Api\Youtube;

use App\Models\SocialAccount;
use Illuminate\Support\Facades\Http;
use Laravel\Socialite\Two\User as SocialiteUser;
use Throwable;

final class YoutubeAccountConnectorService
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
            $resp = Http::withToken((string) ($user->token))->get('https://www.googleapis.com/youtube/v3/channels', [
                'part' => 'id,snippet',
                'mine' => 'true',
            ]);
            $items = $resp->json('items');
            if ($resp->successful() && is_array($items)) {
                $item = (array) ($items[0] ?? []);
                $channelId = (string) ($item['id'] ?? '');
                $snippet = (array) ($item['snippet'] ?? []);
                $channelTitle = (string) ($snippet['title'] ?? '') ?: $channelTitle;
            }
        } catch (Throwable) {
            // segue sem o canal; o token já é suficiente para o upload
        }

        $account = $this->upsert($userId, 'youtube', $channelId ?: $user->getId(), $channelTitle, [
            'access_token' => (string) ($user->token),
            'refresh_token' => (string) ($user->refreshToken) ?: null,
            'token_expires_at' => $user->expiresIn !== null ? now()->addSeconds((int) ($user->expiresIn)) : null,
            'meta' => ['channel_id' => $channelId, 'privacy_status' => 'public'],
        ]);

        return [$account];
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
