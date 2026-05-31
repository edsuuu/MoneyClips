<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\SocialPublishing\OAuth\SocialAccountConnector;
use App\Support\Cast;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\AbstractProvider;
use Laravel\Socialite\Two\GoogleProvider;
use Laravel\Socialite\Two\User as SocialiteUser;
use Throwable;

/**
 * Centraliza todos os fluxos OAuth da aplicação:
 * - loginRedirect / loginCallback  → autenticação do usuário com Google (guest)
 * - connect / callback             → conexão de contas sociais para publicação (auth)
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
    ];

    // ── Google login ──────────────────────────────────────────────────────────

    public function loginRedirect(): RedirectResponse
    {
        if (! $this->googleConfigured()) {
            return to_route('login')->with('status', 'Configure o Google OAuth antes de usar esta opcao.');
        }

        return $this->googleProvider()->redirect();
    }

    public function loginCallback(): RedirectResponse
    {
        if (! $this->googleConfigured()) {
            return to_route('login')->with('status', 'Configure o Google OAuth antes de usar esta opcao.');
        }

        try {
            $socialUser = $this->googleProvider()->user();
            if (! $socialUser instanceof SocialiteUser) {
                return to_route('login')->with('status', 'Resposta inesperada do Google.');
            }

            $email = mb_strtolower(mb_trim((string) $socialUser->getEmail()));
            if ($email === '') {
                return to_route('login')->with('status', 'Sua conta Google nao retornou e-mail.');
            }

            $isNewUser = false;
            $user = User::query()
                ->where('google_id', $socialUser->getId())
                ->orWhere('email', $email)
                ->first();

            if (! $user instanceof User) {
                $user = User::query()->create([
                    'name' => $socialUser->getName() ?: ($socialUser->getNickname() ?: 'Usuario Google'),
                    'email' => $email,
                    'password' => Hash::make(Str::random(40)),
                    'google_id' => $socialUser->getId(),
                    'google_avatar' => $socialUser->getAvatar(),
                    'email_verified_at' => Date::now(),
                ]);
                $isNewUser = true;
            } else {
                $user->forceFill([
                    'name' => $user->name !== '' ? $user->name : ($socialUser->getName() ?: 'Usuario Google'),
                    'google_id' => $socialUser->getId(),
                    'google_avatar' => $socialUser->getAvatar(),
                    'email_verified_at' => $user->email_verified_at ?? Date::now(),
                ])->save();
            }

            if ($isNewUser) {
                event(new Registered($user));
            }

            Auth::login($user, remember: true);
            request()->session()->regenerate();

            return redirect()->intended(route('dashboard'));
        } catch (Throwable $throwable) {
            return to_route('login')->with('status', 'Falha ao autenticar com Google: '.$throwable->getMessage());
        }
    }

    // ── Conexão de contas sociais ─────────────────────────────────────────────

    public function connect(string $platform): RedirectResponse
    {
        // Instagram usa o mesmo OAuth do Facebook (Meta).
        if ($platform === 'instagram') {
            return to_route('oauth.connect', ['platform' => 'facebook']);
        }

        if (! in_array($platform, config('social-publishing.enabled_platforms', ['youtube', 'tiktok']), true)) {
            return to_route('social-accounts')
                ->with('error', 'Plataforma fora do fluxo principal desta aplicacao: '.$platform);
        }

        if ($platform === 'tiktok') {
            return to_route('social-accounts')
                ->with('error', 'TikTok usa conexão manual por token nesta aplicação.');
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

        if (! in_array($platform, config('social-publishing.enabled_platforms', ['youtube', 'tiktok']), true)) {
            return to_route('social-accounts')
                ->with('error', 'Plataforma fora do fluxo principal desta aplicacao: '.$platform);
        }

        if ($platform === 'tiktok') {
            return to_route('social-accounts')
                ->with('error', 'TikTok usa conexão manual por token nesta aplicação.');
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

            $userId = Cast::int(config('social-publishing.account_owner_id', 1));

            $accounts = match ($platform) {
                'youtube' => $connector->fromGoogle($socialUser, $userId),
                'facebook' => $connector->fromMeta($socialUser, $userId),
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

    // ── Helpers privados ──────────────────────────────────────────────────────

    private function googleConfigured(): bool
    {
        return filled(config('services.google_auth.client_id'))
            && filled(config('services.google_auth.client_secret'))
            && filled(config('services.google_auth.redirect'));
    }

    private function googleProvider(): AbstractProvider
    {
        /** @var AbstractProvider $provider */
        $provider = Socialite::buildProvider(GoogleProvider::class, [
            'client_id' => config('services.google_auth.client_id'),
            'client_secret' => config('services.google_auth.client_secret'),
            'redirect' => config('services.google_auth.redirect'),
        ]);

        return $provider->scopes(['openid', 'profile', 'email']);
    }
}
