<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Enums\RoleEnum;
use App\Exceptions\TikTokApiException;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\API\TikTok\TikTokAccountConnectorService;
use App\Services\API\Youtube\YoutubeAccountConnectorService;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
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
    private const string YOUTUBE = 'youtube';

    private const string TIKTOK = 'tiktok';

    private const string TIKTOK_STATE = 'oauth.tiktok.state';

    private const array LOGIN_SCOPES = ['openid', 'profile', 'email'];

    private const array YOUTUBE_CONSENT = [
        'access_type' => 'offline',
        'prompt' => 'consent',
        'include_granted_scopes' => 'true',
    ];

    public function loginRedirect(): RedirectResponse
    {
        return $this->googleProvider('auth')->redirect();
    }

    public function loginCallback(Request $request): RedirectResponse
    {
        try {
            $socialUser = $this->googleProvider('auth')->user();
            if (! $socialUser instanceof SocialiteUser) {
                return to_route('login')->with('status', 'Resposta inesperada do Google.');
            }

            $email = mb_strtolower(mb_trim((string) $socialUser->getEmail()));
            if ($email === '') {
                return to_route('login')->with('status', 'Sua conta Google nao retornou e-mail.');
            }

            $user = User::query()
                ->where('google_id', $socialUser->getId())
                ->orWhere('email', $email)
                ->first();

            if ($user instanceof User) {
                $user->forceFill([
                    'google_id' => $socialUser->getId(),
                    'google_avatar' => $socialUser->getAvatar(),
                    'email_verified_at' => $user->email_verified_at ?? Date::now(),
                ])->save();
            } else {
                $user = DB::transaction(fn (): User => User::query()->create([
                    'name' => $socialUser->getName() ?: $socialUser->getNickname() ?: 'Usuario Google',
                    'email' => $email,
                    'password' => Hash::make(Str::random(40)),
                    'has_password' => false,
                    'google_id' => $socialUser->getId(),
                    'google_avatar' => $socialUser->getAvatar(),
                    'email_verified_at' => Date::now(),
                ])->assignRole(RoleEnum::Creator));

                event(new Registered($user));
            }

            Auth::login($user, remember: true);
            $request->session()->regenerate();
            $request->session()->put('auth.authenticated_via_google', true);

            return redirect()->intended(route('dashboard.index'));
        } catch (Throwable $throwable) {
            Log::channel('daily')->error('[OAuthController] Falha no login com Google.', ['exception' => $throwable]);

            return to_route('login')->with('status', 'Falha ao autenticar com Google. Tente novamente.');
        }
    }

    public function connect(Request $request, string $platform, TikTokAccountConnectorService $tiktok): RedirectResponse
    {
        if ($platform === self::TIKTOK) {
            return $this->connectTiktok($request, $tiktok);
        }

        if ($platform !== self::YOUTUBE) {
            return to_route('accounts.index')->with('error', 'Plataforma não suporta OAuth: '.$platform);
        }

        if (! config('services.google.client_id')) {
            return to_route('accounts.index')
                ->with('error', 'Configure o app do Google (client_id/secret) no .env antes de conectar.');
        }

        return $this->googleProvider(self::YOUTUBE)
            ->scopes(config()->array('services.google.youtube_scopes'))
            ->with(self::YOUTUBE_CONSENT)
            ->redirect();
    }

    public function callback(Request $request, string $platform, YoutubeAccountConnectorService $connector, TikTokAccountConnectorService $tiktok): RedirectResponse
    {
        if ($platform === self::TIKTOK) {
            return $this->tiktokCallback($request, $tiktok);
        }

        if ($platform !== self::YOUTUBE) {
            return to_route('accounts.index')->with('error', 'Plataforma inválida: '.$platform);
        }

        try {
            $socialUser = $this->googleProvider(self::YOUTUBE)->user();
            if (! $socialUser instanceof SocialiteUser) {
                return to_route('accounts.index')->with('error', 'Resposta de OAuth inesperada da plataforma.');
            }

            $userId = Auth::id();
            abort_unless(is_int($userId), 403);

            $accounts = $connector->fromGoogle($socialUser, $userId);
            if ($accounts === []) {
                return to_route('accounts.index')
                    ->with('error', 'Nenhuma conta encontrada. Verifique permissões do OAuth.');
            }

            $names = implode(', ', array_map(static fn ($account): string => $account->name, $accounts));

            return to_route('accounts.index')->with('status', 'Conta(s) conectada(s): '.$names);
        } catch (Throwable $throwable) {
            Log::channel('daily')->error('[OAuthController] Falha no OAuth ('.$platform.').', ['exception' => $throwable]);

            return to_route('accounts.index')->with('error', 'Falha na autenticação OAuth. Tente novamente.');
        }
    }

    private function connectTiktok(Request $request, TikTokAccountConnectorService $tiktok): RedirectResponse
    {
        if (! $tiktok->configured()) {
            return to_route('accounts.index')
                ->with('error', 'Configure o app do TikTok (TIKTOK_CLIENT_KEY/TIKTOK_CLIENT_SECRET) no .env antes de conectar.');
        }

        $state = Str::random(40);
        $request->session()->put(self::TIKTOK_STATE, $state);

        return redirect()->away($tiktok->authorizeUrl($state));
    }

    private function tiktokCallback(Request $request, TikTokAccountConnectorService $tiktok): RedirectResponse
    {
        $expected = $request->session()->pull(self::TIKTOK_STATE);
        if (! is_string($expected) || ! hash_equals($expected, $request->string('state')->toString())) {
            return to_route('accounts.index')->with('error', 'Autorização do TikTok expirou ou não partiu daqui. Tente vincular de novo.');
        }

        if ($request->filled('error') || ! $request->filled('code')) {
            return to_route('accounts.index')->with('error', 'TikTok não autorizou a conta: '.$request->string('error_description', $request->string('error', 'sem código')->toString())->limit(200));
        }

        $userId = Auth::id();
        abort_unless(is_int($userId), 403);

        try {
            $account = $tiktok->fromCallback($request->string('code')->toString(), $userId);
        } catch (TikTokApiException $tikTokApiException) {
            return to_route('accounts.index')->with('error', $tikTokApiException->getMessage());
        }

        return to_route('accounts.index')->with('status', 'Conta TikTok oficial conectada: '.$account->name);
    }

    private function googleProvider(string $redirect): AbstractProvider
    {
        /** @var AbstractProvider $provider */
        $provider = Socialite::buildProvider(GoogleProvider::class, [
            'client_id' => config('services.google.client_id'),
            'client_secret' => config('services.google.client_secret'),
            'redirect' => (string) config('services.google.redirects.'.$redirect),
        ]);

        return $provider->scopes(self::LOGIN_SCOPES);
    }
}
