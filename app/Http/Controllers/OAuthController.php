<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Api\Youtube\YoutubeAccountConnectorService;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\AbstractProvider;
use Laravel\Socialite\Two\GoogleProvider;
use Laravel\Socialite\Two\User as SocialiteUser;
use Throwable;

final class OAuthController extends Controller
{
    private const array PROVIDERS = [
        'youtube' => [
            'driver' => 'google',
            'scopes' => [
                'https://www.googleapis.com/auth/youtube.upload',
                'https://www.googleapis.com/auth/youtube.readonly',
            ],
            'with' => ['access_type' => 'offline', 'prompt' => 'consent', 'include_granted_scopes' => 'true'],
        ],
    ];

    public function loginRedirect(): RedirectResponse
    {
        return $this->googleProvider((string) config('services.google.redirects.auth'))
            ->redirect();
    }

    public function loginCallback(): RedirectResponse
    {
        try {
            $socialUser = $this->googleProvider((string) config('services.google.redirects.auth'))
                ->user();
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
                    'has_password' => false,
                    'google_id' => $socialUser->getId(),
                    'google_avatar' => $socialUser->getAvatar(),
                    'email_verified_at' => Date::now(),
                ]);
                $isNewUser = true;
            } else {
                $user->forceFill([
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
            request()->session()->put('auth.authenticated_via_google', true);

            return redirect()->intended(route('dashboard.index'));
        } catch (Throwable $throwable) {
            Log::channel('daily')->error('[OAuthController] Falha no login com Google.', ['exception' => $throwable]);

            return to_route('login')->with('status', 'Falha ao autenticar com Google. Tente novamente.');
        }
    }

    // ── Conexão de contas sociais ─────────────────────────────────────────────

    public function connect(string $platform): RedirectResponse
    {
        $config = self::PROVIDERS[$platform] ?? null;
        if ($config === null) {
            return to_route('accounts.index')->with('error', 'Plataforma não suporta OAuth: '.$platform);
        }

        if (! config(sprintf('services.%s.client_id', $config['driver']))) {
            return to_route('accounts.index')
                ->with('error', sprintf('Configure o app de %s (client_id/secret) no .env antes de conectar.', $platform));
        }

        $driver = $this->provider($config);

        return $driver
            ->scopes($config['scopes'])
            ->with($config['with'])
            ->redirect();
    }

    public function callback(string $platform, YoutubeAccountConnectorService $connector): RedirectResponse
    {
        $config = self::PROVIDERS[$platform] ?? null;
        if ($config === null) {
            return to_route('accounts.index')->with('error', 'Plataforma inválida: '.$platform);
        }

        try {
            $socialUser = $this->provider($config)->user();
            if (! $socialUser instanceof SocialiteUser) {
                return to_route('accounts.index')->with('error', 'Resposta de OAuth inesperada da plataforma.');
            }

            $userId = Auth::id();
            abort_unless(is_int($userId), 403);

            $accounts = match ($platform) {
                'youtube' => $connector->fromGoogle($socialUser, $userId),
                default => [],
            };

            if ($accounts === []) {
                return to_route('accounts.index')
                    ->with('error', 'Nenhuma conta encontrada. Verifique permissões do OAuth.');
            }

            $names = implode(', ', array_map(static fn ($a): string => $a->name, $accounts));

            return to_route('accounts.index')->with('status', 'Conta(s) conectada(s): '.$names);
        } catch (Throwable $throwable) {
            Log::channel('daily')->error('[OAuthController] Falha no OAuth ('.$platform.').', ['exception' => $throwable]);

            return to_route('accounts.index')->with('error', 'Falha na autenticação OAuth. Tente novamente.');
        }
    }

    /** @param array{driver: string, scopes: array<int, string>, with: array<string, string>} $config */
    private function provider(array $config): AbstractProvider
    {
        if ($config['driver'] === 'google') {
            return $this->googleProvider((string) config('services.google.redirects.youtube'));
        }

        /** @var AbstractProvider $provider */
        $provider = Socialite::driver($config['driver']);

        return $provider;
    }

    private function googleProvider(string $redirect): AbstractProvider
    {
        /** @var AbstractProvider $provider */
        $provider = Socialite::buildProvider(GoogleProvider::class, [
            'client_id' => config('services.google.client_id'),
            'client_secret' => config('services.google.client_secret'),
            'redirect' => $redirect,
        ]);

        return $provider->scopes(['openid', 'profile', 'email']);
    }
}
