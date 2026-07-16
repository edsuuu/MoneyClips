<?php

declare(strict_types=1);

namespace App\Services\Api\Youtube;

use App\Models\SocialAccount;
use Illuminate\Support\Facades\Http;

/**
 * Renova o access_token de uma conta usando o refresh_token quando ele expira.
 * Hoje só YouTube (Google).
 */
final class YoutubeTokenRefresherService
{
    /** Renova se necessário. Devolve true se a conta está utilizável depois. */
    public function ensureFresh(SocialAccount $account): bool
    {
        if (! $account->tokenExpired()) {
            return ! empty($account->access_token);
        }

        if (empty($account->refresh_token)) {
            return false;
        }

        return match ($account->platform) {
            'youtube' => $this->refreshGoogle($account),
            default => false,
        };
    }

    private function refreshGoogle(SocialAccount $account): bool
    {
        $resp = Http::asForm()->post('https://oauth2.googleapis.com/token', [
            'grant_type' => 'refresh_token',
            'refresh_token' => $account->refresh_token,
            'client_id' => config('services.google.client_id'),
            'client_secret' => config('services.google.client_secret'),
        ]);

        if (! $resp->successful() || ! $resp->json('access_token')) {
            return false;
        }

        $account->update([
            'access_token' => (string) ($resp->json('access_token')),
            'token_expires_at' => now()->addSeconds((int) ($resp->json('expires_in') ?? 3600)),
        ]);

        return true;
    }
}
