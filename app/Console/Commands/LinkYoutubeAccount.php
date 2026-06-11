<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\SocialAccount;
use App\Support\Cast;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Vincula um canal do YouTube via OAuth pelo terminal, gerando e guardando o
 * refresh token em social_accounts (platform=youtube) — a mesma conta usada
 * pelo fluxo web (/social-accounts) e pelos publishers.
 *
 * Roda uma única vez por canal; depois as postagens são automáticas.
 *
 * Aceita colar a URL de redirecionamento completa (ele extrai o code sozinho)
 * ou apenas o code. Pode ser passado como argumento para evitar o prompt:
 *
 *   php artisan youtube:link
 *   php artisan youtube:link "http://127.0.0.1:8000/oauth/youtube/callback?code=4/0A..."
 */
final class LinkYoutubeAccount extends Command
{
    private const string AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';

    private const string TOKEN_URL = 'https://oauth2.googleapis.com/token';

    private const string CHANNELS_URL = 'https://www.googleapis.com/youtube/v3/channels';

    private const array SCOPES = [
        'https://www.googleapis.com/auth/youtube.upload',
        'https://www.googleapis.com/auth/youtube.readonly',
    ];

    protected $signature = 'youtube:link
        {input? : URL de redirecionamento completa ou apenas o code}';

    protected $description = 'Vincula uma conta/canal do YouTube via OAuth (gera o refresh token).';

    public function handle(): int
    {
        if (blank(config('services.google.client_id')) || blank(config('services.google.client_secret'))) {
            $this->components->error('Defina GOOGLE_AUTH_CLIENT_ID e GOOGLE_AUTH_CLIENT_SECRET no .env antes de vincular.');

            return self::FAILURE;
        }

        $input = $this->argument('input');

        if (! is_string($input) || mb_trim($input) === '') {
            $this->components->info('Vínculo de conta do YouTube');
            $this->line('1) Abra a URL abaixo e autorize o acesso:');
            $this->newLine();
            $this->line('<fg=cyan>'.$this->authUrl().'</>');
            $this->newLine();
            $this->line('2) Cole aqui a <options=bold>URL de redirecionamento completa</> (ou só o code):');

            $answer = $this->ask('URL ou code');
            $input = is_string($answer) ? $answer : '';
        }

        $code = $this->extractCode($input);
        if ($code === '') {
            $this->components->error('Não consegui extrair o code. Cole a URL completa ou o code.');

            return self::FAILURE;
        }

        try {
            $account = $this->linkFromCode($code);
        } catch (Throwable $throwable) {
            $this->components->error($throwable->getMessage());

            return self::FAILURE;
        }

        $this->components->info(sprintf('Canal vinculado: %s (%s)', $account->name, $account->external_account_id));

        return self::SUCCESS;
    }

    /**
     * URL de consentimento para o usuário autorizar o app.
     */
    private function authUrl(): string
    {
        return self::AUTH_URL.'?'.http_build_query([
            'client_id' => Cast::str(config('services.google.client_id')),
            'redirect_uri' => Cast::str(config('services.google.redirect')),
            'response_type' => 'code',
            'scope' => implode(' ', self::SCOPES),
            'access_type' => 'offline',
            'prompt' => 'consent',
            'include_granted_scopes' => 'true',
        ]);
    }

    /**
     * Troca o code pelo token, descobre o canal e persiste a conta vinculada.
     */
    private function linkFromCode(string $code): SocialAccount
    {
        $tokenResponse = Http::asForm()->timeout(30)->post(self::TOKEN_URL, [
            'code' => $code,
            'client_id' => Cast::str(config('services.google.client_id')),
            'client_secret' => Cast::str(config('services.google.client_secret')),
            'redirect_uri' => Cast::str(config('services.google.redirect')),
            'grant_type' => 'authorization_code',
        ]);

        if (! $tokenResponse->successful()) {
            $message = Cast::str($tokenResponse->json('error_description'))
                ?: Cast::str($tokenResponse->json('error'))
                ?: 'erro desconhecido';

            throw new RuntimeException('Falha no OAuth: '.$message);
        }

        $accessToken = Cast::str($tokenResponse->json('access_token'));
        $refreshToken = Cast::str($tokenResponse->json('refresh_token'));
        $expiresIn = Cast::int($tokenResponse->json('expires_in')) ?: 3600;

        // Descobre o canal autenticado.
        $channelsResponse = Http::withToken($accessToken)
            ->timeout(30)
            ->get(self::CHANNELS_URL, ['part' => 'snippet', 'mine' => 'true']);

        $items = Cast::arr($channelsResponse->json('items'));
        throw_if($items === [], RuntimeException::class, 'Nenhum canal encontrado para esta conta.');

        $channel = Cast::arr($items[0] ?? []);
        $channelId = Cast::str($channel['id'] ?? '');
        $channelTitle = Cast::str(Cast::arr($channel['snippet'] ?? [])['title'] ?? '') ?: $channelId;

        $attributes = [
            'name' => $channelTitle,
            'access_token' => $accessToken,
            'token_expires_at' => Date::now()->addSeconds($expiresIn),
            'scopes' => self::SCOPES,
            'is_active' => true,
        ];

        // refresh_token só vem quando presente (com prompt=consent, vem sempre).
        if ($refreshToken !== '') {
            $attributes['refresh_token'] = $refreshToken;
        }

        return SocialAccount::query()->updateOrCreate(
            ['platform' => 'youtube', 'external_account_id' => $channelId],
            $attributes,
        );
    }

    /**
     * Extrai o code a partir de uma URL de redirecionamento completa ou aceita
     * o próprio code quando já vier isolado.
     */
    private function extractCode(string $input): string
    {
        $input = mb_trim($input);
        if ($input === '') {
            return '';
        }

        // Se colaram a URL inteira, pega o parâmetro ?code=...
        if (str_contains($input, 'code=')) {
            $query = parse_url($input, PHP_URL_QUERY);
            if (is_string($query) && $query !== '') {
                parse_str($query, $params);
                if (isset($params['code']) && is_string($params['code']) && $params['code'] !== '') {
                    return $params['code'];
                }
            }
        }

        return $input;
    }
}
