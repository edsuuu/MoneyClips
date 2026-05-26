<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\SocialPublishing\OAuth\SocialAccountConnector;
use App\Support\Cast;
use Illuminate\Http\RedirectResponse;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\AbstractProvider;
use Laravel\Socialite\Two\User as SocialiteUser;
use Throwable;

/**
 * Conecta contas sociais via OAuth (Socialite), sem token na mão.
 * Google cobre YouTube; Facebook cobre Instagram + Facebook (Meta); TikTok à parte.
 */
final class OAuthController extends Controller
{
    /** Plataforma -> [driver Socialite, escopos, params extras]. */
    private const array PROVIDERS = [
        'youtube' => [
            'driver' => 'google',
            'scopes' => [
                'https://www.googleapis.com/auth/youtube.upload',
                'https://www.googleapis.com/auth/youtube.readonly',
            ],
            'with' => ['access_type' => 'offline', 'prompt' => 'consent', 'include_granted_scopes' => 'true'],
        ],
        'facebook' => [
            'driver' => 'facebook',
            'scopes' => [
                'pages_show_list', 'pages_read_engagement', 'pages_manage_posts',
                'business_management', 'instagram_basic', 'instagram_content_publish',
            ],
            'with' => [],
        ],
        'tiktok' => [
            'driver' => 'tiktok',
            'scopes' => ['user.info.basic', 'video.publish', 'video.upload'],
            'with' => [],
        ],
    ];

    public function connect(string $platform): RedirectResponse
    {
        // Instagram usa o mesmo OAuth do Facebook (Meta).
        if ($platform === 'instagram') {
            return to_route('oauth.connect', ['platform' => 'facebook']);
        }

        $config = self::PROVIDERS[$platform] ?? null;
        if ($config === null) {
            return to_route('social-accounts')->with('error', 'Plataforma não suporta OAuth: '.$platform);
        }

        if (! config(sprintf('services.%s.client_id', $config['driver']))) {
            return to_route('social-accounts')
                ->with('error', sprintf('Configure o app de %s (client_id/secret) no .env antes de conectar.', $platform));
        }

        /** @var AbstractProvider $driver */
        $driver = Socialite::driver($config['driver']);
        $driver = $driver->scopes($config['scopes']);

        if ($config['with'] !== []) {
            $driver = $driver->with($config['with']);
        }

        return $driver->redirect();
    }

    public function callback(string $platform, SocialAccountConnector $connector): RedirectResponse
    {
        if ($platform === 'instagram') {
            $platform = 'facebook';
        }

        $config = self::PROVIDERS[$platform] ?? null;
        if ($config === null) {
            return to_route('social-accounts')->with('error', 'Plataforma inválida: '.$platform);
        }

        try {
            $socialUser = Socialite::driver($config['driver'])->user();
            if (! $socialUser instanceof SocialiteUser) {
                return to_route('social-accounts')->with('error', 'Resposta de OAuth inesperada da plataforma.');
            }

            // Contas ficam sob o usuário admin (default id 1), não sob quem clicou.
            $userId = Cast::int(config('social-publishing.account_owner_id', 1));

            $accounts = match ($platform) {
                'youtube' => $connector->fromGoogle($socialUser, $userId),
                'facebook' => $connector->fromMeta($socialUser, $userId),
                'tiktok' => $connector->fromTikTok($socialUser, $userId),
                default => [],
            };

            if ($accounts === []) {
                return to_route('social-accounts')
                    ->with('error', 'Nenhuma conta encontrada. Verifique permissões/Páginas vinculadas.');
            }

            $names = implode(', ', array_map(static fn ($a): string => $a->name, $accounts));

            return to_route('social-accounts')->with('status', 'Conta(s) conectada(s): '.$names);
        } catch (Throwable $throwable) {
            return to_route('social-accounts')->with('error', 'Falha no OAuth: '.$throwable->getMessage());
        }
    }
}
