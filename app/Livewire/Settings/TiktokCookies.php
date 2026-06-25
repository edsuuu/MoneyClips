<?php

declare(strict_types=1);

namespace App\Livewire\Settings;

use App\Livewire\Concerns\WithToasts;
use App\Models\SocialAccount;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use JsonException;
use Livewire\Attributes\Validate;
use Livewire\Component;

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
