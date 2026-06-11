<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\SocialPublishing\OAuth\SocialAccountConnector;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
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
    private const string TIKTOK_AUTHORIZE_URL = 'https://www.tiktok.com/v2/auth/authorize/';

    private const string TIKTOK_TOKEN_URL = 'https://open.tiktokapis.com/v2/oauth/token/';

    private const string TIKTOK_USER_INFO_URL = 'https://open.tiktokapis.com/v2/user/info/';

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

    public function loginRedirect(): RedirectResponse
    {
        return $this->googleProvider()->redirect();
    }

    public function loginCallback(): RedirectResponse
    {
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

            return redirect()->intended(route('dashboard'));
        } catch (Throwable $throwable) {
            Log::channel('daily')->error('[OAuthController] Falha no login com Google.', ['exception' => $throwable]);

            return to_route('login')->with('status', 'Falha ao autenticar com Google. Tente novamente.');
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
            if (! $this->tiktokConfigured()) {
                return to_route('social-accounts')
                    ->with('error', 'Configure TIKTOK_CLIENT_KEY, TIKTOK_CLIENT_SECRET e TIKTOK_REDIRECT_URI no .env antes de conectar.');
            }

            $state = Str::random(40);
            request()->session()->put('oauth.tiktok.state', $state);

            $query = http_build_query([
                'client_key' => config('services.tiktok.client_key'),
                'response_type' => 'code',
                'scope' => 'user.info.basic,video.publish',
                'redirect_uri' => config('services.tiktok.redirect'),
                'state' => $state,
                'disable_auto_auth' => 0,
            ]);

            return redirect()->away(self::TIKTOK_AUTHORIZE_URL.'?'.$query);
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
            if (! $this->tiktokConfigured()) {
                return to_route('social-accounts')
                    ->with('error', 'Configure TIKTOK_CLIENT_KEY, TIKTOK_CLIENT_SECRET e TIKTOK_REDIRECT_URI no .env antes de conectar.');
            }

            $error = mb_trim((string) request()->query('error', ''));
            if ($error !== '') {
                $description = mb_trim((string) request()->query('error_description', ''));

                return to_route('social-accounts')
                    ->with('error', 'Falha no OAuth do TikTok: '.($description !== '' ? $description : $error));
            }

            $expectedState = (string) request()->session()->pull('oauth.tiktok.state', '');
            $state = mb_trim((string) request()->query('state', ''));
            if ($expectedState === '' || ! hash_equals($expectedState, $state)) {
                return to_route('social-accounts')->with('error', 'Falha no OAuth do TikTok: state inválido.');
            }

            $code = mb_trim((string) request()->query('code', ''));
            if ($code === '') {
                return to_route('social-accounts')->with('error', 'Falha no OAuth do TikTok: código ausente.');
            }

            try {
                $tokenResponse = Http::asForm()->post(self::TIKTOK_TOKEN_URL, [
                    'client_key' => config('services.tiktok.client_key'),
                    'client_secret' => config('services.tiktok.client_secret'),
                    'code' => $code,
                    'grant_type' => 'authorization_code',
                    'redirect_uri' => config('services.tiktok.redirect'),
                ]);

                if (! $tokenResponse->successful() || blank($tokenResponse->json('access_token'))) {
                    $message = Cast::str($tokenResponse->json('error_description'))
                        ?: Cast::str($tokenResponse->json('message'))
                        ?: Cast::str($tokenResponse->body());

                    return to_route('social-accounts')
                        ->with('error', 'Falha ao trocar o código do TikTok por token: '.$message);
                }

                $userId = Auth::id();
                abort_unless(is_int($userId), 403);

                $accessToken = Cast::str($tokenResponse->json('access_token'));
                $profileResponse = Http::withToken($accessToken)->get(self::TIKTOK_USER_INFO_URL, [
                    'fields' => 'open_id,display_name,avatar_url',
                ]);

                $profile = $profileResponse->successful()
                    ? Cast::arr($profileResponse->json('data.user'))
                    : [];

                $accounts = $connector->fromTikTokTokenBundle(
                    Cast::arr($tokenResponse->json()),
                    $profile,
                    $userId,
                );

                if ($accounts === []) {
                    return to_route('social-accounts')
                        ->with('error', 'Nenhuma conta do TikTok foi retornada.');
                }

                $names = implode(', ', array_map(static fn ($a): string => $a->name, $accounts));

                return to_route('social-accounts')->with('status', 'Conta(s) conectada(s): '.$names);
            } catch (Throwable $throwable) {
                Log::channel('daily')->error('[OAuthController] Falha no OAuth do TikTok.', ['exception' => $throwable]);

                return to_route('social-accounts')->with('error', 'Falha no OAuth do TikTok. Tente novamente.');
            }
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

            $userId = Auth::id();
            abort_unless(is_int($userId), 403);

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
            Log::channel('daily')->error('[OAuthController] Falha no OAuth ('.$platform.').', ['exception' => $throwable]);

            return to_route('social-accounts')->with('error', 'Falha na autenticação OAuth. Tente novamente.');
        }
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

    private function tiktokConfigured(): bool
    {
        return filled(config('services.tiktok.client_key'))
            && filled(config('services.tiktok.client_secret'))
            && filled(config('services.tiktok.redirect'));
    }
}
