<?php

declare(strict_types=1);

namespace App\Livewire\Social;

use App\Models\SocialAccount;
use App\Services\SocialPublishing\SocialPublisherRegistry;
use Flux\Flux;
use Illuminate\Support\Facades\Date;
use Illuminate\View\View;
use Livewire\Attributes\Validate;
use Livewire\Component;

/**
 * Conecta contas das plataformas guardando o token OAuth (criptografado).
 * Enquanto não há o fluxo OAuth redirect completo, o usuário cola o access
 * token/IDs obtidos no console de desenvolvedor de cada plataforma.
 */
final class Accounts extends Component
{
    #[Validate('required|string')]
    public string $platform = 'youtube';

    #[Validate('required|string|max:255')]
    public string $name = '';

    public string $external_account_id = '';

    #[Validate('required|string')]
    public string $access_token = '';

    public string $refresh_token = '';

    public string $token_expires_at = '';

    /** JSON livre com extras por plataforma (ig_user_id, page_id, privacy_level...). */
    public string $meta = '';

    public function save(): void
    {
        $this->validate();

        if (! in_array($this->platform, SocialAccount::PLATFORMS, true)) {
            Flux::toast('Plataforma inválida.', variant: 'danger');

            return;
        }

        $meta = null;
        if (mb_trim($this->meta) !== '') {
            $decoded = json_decode($this->meta, true);
            if (! is_array($decoded)) {
                Flux::toast('O campo Meta precisa ser um JSON válido.', variant: 'danger');

                return;
            }

            $meta = $decoded;
        }

        $configuredOwnerId = config('social-publishing.account_owner_id', 1);
        $ownerId = is_numeric($configuredOwnerId) ? (int) $configuredOwnerId : 1;

        SocialAccount::query()->create([
            'user_id' => $ownerId,
            'platform' => $this->platform,
            'name' => $this->name,
            'external_account_id' => $this->external_account_id ?: null,
            'access_token' => $this->access_token,
            'refresh_token' => $this->refresh_token ?: null,
            'token_expires_at' => $this->token_expires_at !== '' ? Date::parse($this->token_expires_at) : null,
            'meta' => $meta,
            'is_active' => true,
        ]);

        $this->reset(['name', 'external_account_id', 'access_token', 'refresh_token', 'token_expires_at', 'meta']);
        Flux::toast('Conta conectada.');
    }

    public function toggleActive(int $id): void
    {
        $account = SocialAccount::query()->find($id);
        if ($account instanceof SocialAccount) {
            $account->update(['is_active' => ! $account->is_active]);
        }
    }

    public function delete(int $id): void
    {
        SocialAccount::query()->whereKey($id)->delete();
        Flux::toast('Conta removida.');
    }

    public function render(SocialPublisherRegistry $registry): View
    {
        return view('livewire.social.accounts', [
            'platformLabels' => $registry->labels(),
            'accounts' => SocialAccount::query()->latest()->get(),
        ]);
    }
}
