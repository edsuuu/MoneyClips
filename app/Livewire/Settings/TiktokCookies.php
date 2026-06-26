<?php

declare(strict_types=1);

namespace App\Livewire\Settings;

use App\Livewire\Concerns\WithToasts;
use App\Models\SocialAccount;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\View\View;
use JsonException;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Throwable;

/**
 * Card de gestão da sessão TikTok (cookies).
 *
 * Sem OAuth oficial pra TikTok, a autenticação é por cookies do Playwright.
 * O usuário cola o JSON exportado e salvamos criptografado em
 * social_accounts.cookies. O microserviço recebe esses cookies a cada
 * POST /posts e devolve eventuais refreshs no webhook (callback atualiza).
 */
final class TiktokCookies extends Component
{
    use WithToasts;

    /** Handle público da conta (ex.: clipsd211). */
    #[Validate('required|string|max:64')]
    public string $accountName = '';

    /** Textarea: JSON do array de cookies (Playwright export). */
    public string $cookiesJson = '';

    public bool $isTestingSession = false;

    public bool $isAttemptingLogin = false;

    public function mount(): void
    {
        $account = $this->loadAccount();
        if ($account instanceof SocialAccount) {
            $this->accountName = (string) $account->name;
        } else {
            $this->accountName = (string) config('services.tiktok_post.account_name', '');
        }
    }

    public function saveCookies(): void
    {
        $this->validate([
            'accountName' => ['required', 'string', 'max:64'],
            'cookiesJson' => ['required', 'string'],
        ]);

        try {
            /** @var array<int, array<string, mixed>> $decoded */
            $decoded = json_decode($this->cookiesJson, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $jsonException) {
            $this->toast('JSON inválido: '.$jsonException->getMessage(), 'danger');

            return;
        }

        if ($decoded === []) {
            $this->toast('Cole um array JSON com pelo menos 1 cookie.', 'danger');

            return;
        }

        $account = $this->loadAccount() ?? $this->makeAccount();
        $account->name = $this->accountName;
        $account->cookies = $decoded;
        $account->session_status = SocialAccount::SESSION_UNKNOWN;
        $account->is_active = true;
        $account->save();

        $this->cookiesJson = '';
        $this->toast(sprintf('Cookies atualizados (%d entradas). Próximo post valida a sessão.', count($decoded)));
    }

    public function testSession(): void
    {
        $this->isTestingSession = true;
        try {
            $baseUrl = (string) config('services.tiktok_post.base_url', 'http://127.0.0.1:8090');
            $response = Http::timeout(10)->get($baseUrl . '/session');

            if (! $response->successful()) {
                $this->toast('Microserviço indisponível ou não respondeu.', 'danger');

                return;
            }

            /** @var array<string, mixed> $data */
            $data = $response->json();
            $valid = (bool) ($data['valid'] ?? false);
            $expired = (bool) ($data['expired'] ?? false);
            $hasCookies = (bool) ($data['has_cookies'] ?? false);

            if (! $hasCookies) {
                $this->toast('Nenhum cookie encontrado no microserviço.', 'warning');

                return;
            }

            if ($expired) {
                $this->toast('Cookies expirados. Tente fazer login novamente.', 'warning');

                return;
            }

            if ($valid) {
                $this->toast('✅ Sessão TikTok válida!', 'success');
                // Atualiza status no banco
                $account = $this->loadAccount();
                if ($account instanceof SocialAccount) {
                    $account->session_status = SocialAccount::SESSION_VALID;
                    $account->cookies_last_validated_at = now();
                    $account->save();
                }

                return;
            }

            $this->toast('Sessão desconhecida — tente fazer login.', 'warning');
        } catch (ConnectionException) {
            $this->toast('Não consegui conectar ao microserviço TikTok.', 'danger');
        } catch (Throwable $e) {
            $this->toast('Erro ao testar sessão: '.$e->getMessage(), 'danger');
        } finally {
            $this->isTestingSession = false;
        }
    }

    public function attemptLogin(): void
    {
        $this->isAttemptingLogin = true;
        try {
            $baseUrl = (string) config('services.tiktok_post.base_url', 'http://127.0.0.1:8090');
            $response = Http::timeout(30)->post($baseUrl . '/login', [
                'force' => true,
            ]);

            if (! $response->successful()) {
                $this->toast('Microserviço indisponível. Tente novamente.', 'danger');

                return;
            }

            /** @var array<string, mixed> $data */
            $data = $response->json();
            $valid = (bool) ($data['valid'] ?? false);

            if ($valid) {
                $this->toast('✅ Login bem-sucedido! Cookies atualizados no uploader.', 'success');

                // Próximo post vai capturar os cookies refrescados
                return;
            }

            $this->toast('Login falhou. Verifique as credenciais de email/senha no .env', 'danger');
        } catch (ConnectionException) {
            $this->toast('Não consegui conectar ao microserviço TikTok.', 'danger');
        } catch (Throwable $e) {
            $this->toast('Erro ao fazer login: '.$e->getMessage(), 'danger');
        } finally {
            $this->isAttemptingLogin = false;
        }
    }

    public function render(): View
    {
        return view('livewire.settings.tiktok-cookies', [
            'account' => $this->loadAccount(),
        ]);
    }

    private function loadAccount(): ?SocialAccount
    {
        return SocialAccount::query()
            ->where('platform', 'tiktok')
            ->latest('id')
            ->first();
    }

    private function makeAccount(): SocialAccount
    {
        $userId = Auth::id();
        $account = new SocialAccount;
        $account->platform = 'tiktok';
        $account->user_id = is_numeric($userId) ? max(0, (int) $userId) : null;
        $account->is_active = true;

        return $account;
    }
}
